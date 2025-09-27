from flask import Flask, request, jsonify
from transformers import GPT2LMHeadModel, GPT2Tokenizer
import torch
import os

app = Flask(__name__)

# Load GPT-2 model and tokenizer once at startup
MODEL_NAME = "gpt2"  # you can use "gpt2-medium" if memory allows
tokenizer = GPT2Tokenizer.from_pretrained(MODEL_NAME)
model = GPT2LMHeadModel.from_pretrained(MODEL_NAME)

@app.route("/")
def home():
    return "GPT-2 API is running!"

@app.route("/generate", methods=["POST"])
def generate():
    data = request.get_json()
    prompt = data.get("prompt", "")
    max_length = data.get("max_length", 100)

    inputs = tokenizer.encode(prompt, return_tensors="pt")
    outputs = model.generate(inputs, max_length=max_length, do_sample=True, top_k=50)
    text = tokenizer.decode(outputs[0], skip_special_tokens=True)
    return jsonify({"generated_text": text})

if __name__ == "__main__":
    port = int(os.environ.get("PORT", 10000))
    app.run(host="0.0.0.0", port=port)
