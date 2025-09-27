from flask import Flask, request, jsonify, Response
from flask_cors import CORS
import requests
from langchain.llms.base import LLM
from langchain.agents import initialize_agent, Tool, AgentType
from langchain.memory import ConversationBufferMemory
import os
import time

app = Flask(__name__)
CORS(app)

# ---------------- Pollinations LLM Wrapper ----------------
class PollinationsLLM(LLM):
    def __init__(self):
        self.api_base = "https://text.pollinations.ai"

    @property
    def _llm_type(self):
        return "pollinations"

    def _call(self, prompt: str, stop=None):
        url = f"{self.api_base}/"
        payload = {"prompt": prompt}
        try:
            response = requests.post(url, json=payload)
            if response.status_code == 200:
                return response.json().get("text", "")
            else:
                return f"Error: {response.text}"
        except Exception as e:
            return f"Exception: {str(e)}"

# Initialize LLM
llm = PollinationsLLM()

# ---------------- Tools ----------------
def calculator_tool(query: str) -> str:
    try:
        return str(eval(query))
    except Exception as e:
        return f"Error: {e}"

def generate_image(prompt: str) -> str:
    try:
        url = f"https://image.pollinations.ai/prompt/{prompt}"
        return url
    except Exception as e:
        return f"Error: {e}"

tools = [
    Tool(
        name="Calculator",
        func=calculator_tool,
        description="Performs basic math calculations"
    ),
    Tool(
        name="ImageGenerator",
        func=generate_image,
        description="Generates images from text prompts using Pollinations.ai"
    )
]

# ---------------- Memory ----------------
memory = ConversationBufferMemory(memory_key="chat_history", return_messages=True)

# ---------------- Initialize Agent ----------------
agent = initialize_agent(
    tools,
    llm,
    agent=AgentType.ZERO_SHOT_REACT_DESCRIPTION,
    memory=memory,
    verbose=True
)

# ---------------- Streaming helper ----------------
def stream_response(prompt: str):
    # Split the response into chunks and stream
    full_response = agent.run(prompt)
    chunk_size = 50
    for i in range(0, len(full_response), chunk_size):
        yield full_response[i:i+chunk_size]
        time.sleep(0.05)  # simulate streaming

# ---------------- Routes ----------------
@app.route("/")
def home():
    return "Pollinations.ai LangChain Agent with Memory and Streaming is running!"

@app.route("/ask", methods=["POST"])
def ask():
    data = request.get_json()
    prompt = data.get("prompt", "")
    return Response(stream_response(prompt), mimetype="text/plain")

@app.route("/image", methods=["POST"])
def image():
    data = request.get_json()
    prompt = data.get("prompt", "")
    image_url = generate_image(prompt)
    return jsonify({"image_url": image_url})

if __name__ == "__main__":
    port = int(os.environ.get("PORT", 10000))
    app.run(host="0.0.0.0", port=port)
