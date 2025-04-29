import whisper
import whisperx
import json
import sys
import os
import http.server
import torch
import gc
from pyannote.audio import Pipeline
import traceback

PORT = 8080
if 'PORT' in os.environ:
    PORT = int(os.environ['PORT'])

device = 'cuda'
model_pool = {}
def get_model(module, model_id):
    if model_id in model_pool:
        model_pool[model_id]["last_used_at"] = time.time()
        return model_pool[model_id]['model']
    else:
        # stderr 回傳時間和 model_id
        print(f"load model: {time.strftime('%Y-%m-%d %H:%M:%S', time.localtime())}, model_id: {model_id}", file=sys.stderr)
        start_time = time.time()
        if (model_id == 'pyannote'):
            pipeline = Pipeline.from_pretrained("pyannote/speaker-diarization-3.1")
            # send pipeline to GPU (when available)
            pipeline.to(torch.device(device))
            model = pipeline
            
        else:
            model = module.load_model(model_id, device=device)
        delta = time.time() - start_time
        # stderr 回傳時間和 model_id
        print(f"load model: {time.strftime('%Y-%m-%d %H:%M:%S', time.localtime())}, model_id: {model_id}, time: {delta:.2f} seconds", file=sys.stderr)
        model_pool[model_id] = {
            "model": model,
            "last_used_at": time.time()
        }
        return model
    

# 持續從 stdin 讀取設定，一行一個指令
# stdin 讀取設定，Ex: {"id":"12345","input":"test.mp3", "language":"zh"}
# stdout 輸出結果，Ex: {"id":"12345","output":"whisper輸出結果"}
class MyHandler(http.server.BaseHTTPRequestHandler):
    def do_POST(self):
        # 接收 POST request 的內容
        content_length = int(self.headers['Content-Length'])
        post_data = self.rfile.read(content_length).decode('utf-8')
        # json decode
        post_data = json.loads(post_data)
        try:
            # 取得 id 和 input
            method = post_data.get("method", "whisper")
            id = post_data["id"]
            input_file = post_data["input"]
            if method == 'whisper':
                language = post_data.get("language", "zh")
                clip_timestamps = post_data.get("clip_timestamps", "0")
                model_id = post_data.get("model_id", "turbo")
                
                # stderr 輸出現在時間、id 和檔案名稱
                print(f"process: {time.strftime('%Y-%m-%d %H:%M:%S', time.localtime())}, id: {id}, file: {input_file}", file=sys.stderr)
                # 使用 Whisper 模型進行轉錄
                start_time = time.time()
                model = get_model(whisper, model_id)
                result = model.transcribe(input_file, language=language, clip_timestamps=clip_timestamps)
                delta = time.time() - start_time
                # stderr 輸出轉錄時間
                print(f"process: {time.strftime('%Y-%m-%d %H:%M:%S', time.localtime())}, id: {id}, file: {input_file}, time: {delta:.2f} seconds", file=sys.stderr)
                
                # 輸出結果
                output = json.dumps({
                    "id": id,
                    "output": result
                })
            elif method == 'whisperx':
                language = post_data.get("language", "zh")
                model_id = post_data.get("model_id", "turbo")
                diarize = post_data.get("diarize", False)

                # stderr 輸出現在時間、id 和檔案名稱
                print(f"process: {time.strftime('%Y-%m-%d %H:%M:%S', time.localtime())}, id: {id}, file: {input_file}", file=sys.stderr)
                # 使用 Whisperx 模型進行轉錄
                start_time = time.time()
                model = get_model(whisperx, model_id)
                audio = whisperx.load_audio(input_file)
                result = model.transcribe(audio, language=language)
                delta = time.time() - start_time

                if diarize:
                    # Align whisper output
                    model_a, metadata = whisperx.load_align_model(language_code=result["language"], device=device)
                    result = whisperx.align(result["segments"], model_a, metadata, audio, device, return_char_alignments=True)

                    # Assign speaker labels
                    diarize_model = whisperx.DiarizationPipeline(device=device)
                    diarize_segments = diarize_model(audio)
                    result = whisperx.assign_word_speakers(diarize_segments, result)

                # stderr 輸出轉錄時間
                print(f"process: {time.strftime('%Y-%m-%d %H:%M:%S', time.localtime())}, id: {id}, file: {input_file}, time: {delta:.2f} seconds", file=sys.stderr)
                
                # 輸出結果
                output = json.dumps({
                    "id": id,
                    "output": result
                })
            elif method == 'pyannote':
                model = get_model(whisper, 'pyannote')
                diarization, embeddings = model(input_file, return_embeddings=True)
                # print the result
                sentences = []
                for turn, _, speaker in diarization.itertracks(yield_label=True):
                    sentences.append({
                        "start": turn.start,
                        "end": turn.end,
                        "speaker": speaker,
                        "classes": diarization.labels(),
                    })
                vectors = []
                for s, speaker in enumerate(diarization.labels()):
                    vectors.append({
                        "speaker": speaker,
                        "vector": embeddings[s].tolist()
                    })
                output = json.dumps({
                    "id": id,
                    "sentences": sentences,
                    "vectors": vectors,

                })
            elif method == 'unload-model':
                for model_id in list(model_pool.keys()):
                    del model_pool[model_id]['model']
                    del model_pool[model_id]
                    gc.collect()
                    torch.cuda.empty_cache()
                    # stderr 回傳時間和 model_id
                    print(f"unload model: {time.strftime('%Y-%m-%d %H:%M:%S', time.localtime())}, model_id: {model_id}", file=sys.stderr)

                output = json.dumps({
                    "id": id,
                    "output": "unload model success"
                })


            self.send_response(200)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            self.wfile.write(output.encode('utf-8'))
        except Exception as e:
            traceback.print_exc()
            # 輸出錯誤訊息
            print(f"Error: {e}", file=sys.stderr)
            self.send_response(500)
            self.send_header('Content-type', 'application/json')
            self.end_headers()
            error_response = json.dumps({
                "error": str(e)
            }) 
            self.wfile.write(error_response.encode('utf-8'))
        
# 在背景每五分鐘檢查一次，若有 model 超過 10 分鐘未使用，則釋放
import time
def release_model():
    while True:
        time.sleep(10)
        for model_id in list(model_pool.keys()):
            if time.time() - model_pool[model_id]["last_used_at"] > 600:
                del model_pool[model_id]['model']
                del model_pool[model_id]
                gc.collect()
                torch.cuda.empty_cache()
                # stderr 回傳時間和 model_id
                print(f"release model: {time.strftime('%Y-%m-%d %H:%M:%S', time.localtime())}, model_id: {model_id}", file=sys.stderr)

# 啟動釋放 model 的背景執行緒
import threading
release_thread = threading.Thread(target=release_model)
release_thread.daemon = True
release_thread.start()

# 啟動 HTTP Server
with http.server.HTTPServer(('0.0.0.0', PORT), MyHandler) as httpd:
    print(f'serving at http://localhost:{PORT}')
    httpd.serve_forever()
