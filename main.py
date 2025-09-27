"""
LangChain AI Agent with Pollinations.ai API
Deployable on Render with Docker
"""

import os
import json
import requests
from typing import Optional, Dict, Any, List
from fastapi import FastAPI, HTTPException
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
from langchain.agents import Tool, AgentExecutor, create_react_agent
from langchain.memory import ConversationBufferMemory
from langchain.prompts import PromptTemplate
from langchain_community.llms import HuggingFaceHub
from langchain.llms.base import LLM
from langchain.callbacks.manager import CallbackManagerForLLMRun
import uvicorn
import logging

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# FastAPI app initialization
app = FastAPI(title="Pollinations AI Agent", version="1.0.0")

# Add CORS middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Request/Response models
class AgentRequest(BaseModel):
    query: str
    session_id: Optional[str] = "default"

class AgentResponse(BaseModel):
    response: str
    session_id: str
    tools_used: List[str]

class ImageGenerationRequest(BaseModel):
    prompt: str
    model: Optional[str] = None
    width: Optional[int] = 1024
    height: Optional[int] = 1024

class TextGenerationRequest(BaseModel):
    prompt: str
    model: Optional[str] = "openai"
    temperature: Optional[float] = 0.7
    max_tokens: Optional[int] = 500

class AudioGenerationRequest(BaseModel):
    prompt: str
    voice: Optional[str] = "alloy"

# Custom LLM wrapper for Pollinations.ai
class PollinationsLLM(LLM):
    """Custom LLM wrapper for Pollinations.ai API"""
    
    api_url: str = "https://text.pollinations.ai/"
    model: str = "openai"
    temperature: float = 0.7
    
    @property
    def _llm_type(self) -> str:
        return "pollinations"
    
    def _call(
        self,
        prompt: str,
        stop: Optional[List[str]] = None,
        run_manager: Optional[CallbackManagerForLLMRun] = None,
    ) -> str:
        """Call Pollinations.ai text generation API"""
        try:
            payload = {
                "messages": [{"role": "user", "content": prompt}],
                "model": self.model,
                "temperature": self.temperature,
                "stream": False
            }
            
            response = requests.post(
                self.api_url,
                json=payload,
                headers={"Content-Type": "application/json"}
            )
            response.raise_for_status()
            
            result = response.json()
            if isinstance(result, dict) and "choices" in result:
                return result["choices"][0]["message"]["content"]
            return str(result)
            
        except Exception as e:
            logger.error(f"Error calling Pollinations API: {e}")
            return f"Error generating text: {str(e)}"

# Tool functions for the agent
def generate_image(prompt: str) -> str:
    """Generate an image using Pollinations.ai"""
    try:
        # URL encode the prompt
        from urllib.parse import quote
        encoded_prompt = quote(prompt)
        image_url = f"https://image.pollinations.ai/prompt/{encoded_prompt}"
        
        # Verify the image can be generated
        response = requests.head(image_url)
        if response.status_code == 200:
            return f"Image generated successfully! View it at: {image_url}"
        else:
            return f"Failed to generate image. Status: {response.status_code}"
    except Exception as e:
        logger.error(f"Error generating image: {e}")
        return f"Error generating image: {str(e)}"

def generate_audio(prompt: str, voice: str = "alloy") -> str:
    """Generate audio using Pollinations.ai"""
    try:
        from urllib.parse import quote
        encoded_prompt = quote(prompt)
        audio_url = f"https://text.pollinations.ai/{encoded_prompt}?model=openai-audio&voice={voice}"
        
        # Check if audio can be generated
        response = requests.head(audio_url)
        if response.status_code == 200:
            return f"Audio generated successfully! Listen at: {audio_url}"
        else:
            return f"Failed to generate audio. Status: {response.status_code}"
    except Exception as e:
        logger.error(f"Error generating audio: {e}")
        return f"Error generating audio: {str(e)}"

def get_available_models(model_type: str = "text") -> str:
    """Get list of available models from Pollinations.ai"""
    try:
        if model_type == "text":
            url = "https://text.pollinations.ai/models"
        elif model_type == "image":
            url = "https://image.pollinations.ai/models"
        else:
            return "Invalid model type. Use 'text' or 'image'"
        
        response = requests.get(url)
        response.raise_for_status()
        models = response.json()
        return f"Available {model_type} models: {json.dumps(models, indent=2)}"
    except Exception as e:
        logger.error(f"Error fetching models: {e}")
        return f"Error fetching models: {str(e)}"

def get_feed(feed_type: str = "image") -> str:
    """Get real-time feed from Pollinations.ai"""
    try:
        if feed_type == "image":
            url = "https://image.pollinations.ai/feed"
        elif feed_type == "text":
            url = "https://text.pollinations.ai/feed"
        else:
            return "Invalid feed type. Use 'image' or 'text'"
        
        response = requests.get(url)
        response.raise_for_status()
        feed_data = response.json()
        # Limit to first 5 items for brevity
        if isinstance(feed_data, list):
            feed_data = feed_data[:5]
        return f"Latest {feed_type} feed items: {json.dumps(feed_data, indent=2)}"
    except Exception as e:
        logger.error(f"Error fetching feed: {e}")
        return f"Error fetching feed: {str(e)}"

# Create tools for the agent
tools = [
    Tool(
        name="GenerateImage",
        func=generate_image,
        description="Generate an image from a text prompt. Input should be a descriptive prompt for the image."
    ),
    Tool(
        name="GenerateAudio",
        func=lambda x: generate_audio(x.split("|")[0], x.split("|")[1] if "|" in x else "alloy"),
        description="Generate audio/speech from text. Input format: 'text|voice' where voice is optional (default: alloy). Available voices: alloy, echo, fable, onyx, nova, shimmer"
    ),
    Tool(
        name="GetModels",
        func=lambda x: get_available_models(x),
        description="Get available models. Input should be either 'text' or 'image'"
    ),
    Tool(
        name="GetFeed",
        func=lambda x: get_feed(x),
        description="Get real-time feed of recent generations. Input should be either 'image' or 'text'"
    )
]

# Agent prompt template
agent_prompt = PromptTemplate(
    input_variables=["input", "tools", "tool_names", "agent_scratchpad"],
    template="""You are a helpful AI assistant powered by Pollinations.ai APIs. You can:
1. Generate images from text descriptions
2. Generate audio/speech from text
3. Answer questions and have conversations
4. Check available models
5. Get feeds of recent generations

You have access to the following tools:
{tools}

Tool Names: {tool_names}

To use a tool, please use the following format:
Thought: I need to [describe what you want to do]
Action: [tool name]
Action Input: [input for the tool]
Observation: [tool's response]
... (repeat Thought/Action/Action Input/Observation as needed)
Thought: I now have the final answer
Final Answer: [your response to the user]

If you don't need to use any tools, just provide a direct answer.
Current user input: {input}

Begin!
{agent_scratchpad}"""
)

# Store agent sessions
agent_sessions: Dict[str, AgentExecutor] = {}

def get_or_create_agent(session_id: str) -> AgentExecutor:
    """Get existing agent or create new one for session"""
    if session_id not in agent_sessions:
        # Initialize LLM
        llm = PollinationsLLM(temperature=0.7)
        
        # Create memory
        memory = ConversationBufferMemory(
            memory_key="chat_history",
            return_messages=True
        )
        
        # Create agent
        agent = create_react_agent(
            llm=llm,
            tools=tools,
            prompt=agent_prompt
        )
        
        # Create agent executor
        agent_executor = AgentExecutor(
            agent=agent,
            tools=tools,
            memory=memory,
            verbose=True,
            max_iterations=5,
            handle_parsing_errors=True
        )
        
        agent_sessions[session_id] = agent_executor
    
    return agent_sessions[session_id]

# API Endpoints
@app.get("/")
async def root():
    """Root endpoint with API information"""
    return {
        "name": "Pollinations AI Agent API",
        "version": "1.0.0",
        "endpoints": {
            "/chat": "Main chat endpoint for agent interaction",
            "/generate/image": "Direct image generation",
            "/generate/text": "Direct text generation",
            "/generate/audio": "Direct audio generation",
            "/models": "List available models",
            "/health": "Health check"
        },
        "powered_by": "Pollinations.ai"
    }

@app.post("/chat", response_model=AgentResponse)
async def chat(request: AgentRequest):
    """Main chat endpoint for agent interaction"""
    try:
        agent = get_or_create_agent(request.session_id)
        
        # Track which tools were used
        tools_used = []
        
        # Run agent
        result = agent.invoke({"input": request.query})
        
        # Extract tools used from verbose output (simplified)
        if "intermediate_steps" in result:
            for step in result.get("intermediate_steps", []):
                if len(step) > 0 and hasattr(step[0], "tool"):
                    tools_used.append(step[0].tool)
        
        return AgentResponse(
            response=result.get("output", "No response generated"),
            session_id=request.session_id,
            tools_used=tools_used
        )
    except Exception as e:
        logger.error(f"Chat error: {e}")
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/generate/image")
async def generate_image_endpoint(request: ImageGenerationRequest):
    """Direct image generation endpoint"""
    try:
        result = generate_image(request.prompt)
        return {"success": True, "result": result}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/generate/text")
async def generate_text_endpoint(request: TextGenerationRequest):
    """Direct text generation endpoint"""
    try:
        llm = PollinationsLLM(
            model=request.model,
            temperature=request.temperature
        )
        result = llm._call(request.prompt)
        return {"success": True, "result": result}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.post("/generate/audio")
async def generate_audio_endpoint(request: AudioGenerationRequest):
    """Direct audio generation endpoint"""
    try:
        result = generate_audio(request.prompt, request.voice)
        return {"success": True, "result": result}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/models/{model_type}")
async def get_models(model_type: str):
    """Get available models"""
    try:
        result = get_available_models(model_type)
        return {"success": True, "result": result}
    except Exception as e:
        raise HTTPException(status_code=500, detail=str(e))

@app.get("/health")
async def health_check():
    """Health check endpoint"""
    return {"status": "healthy", "service": "Pollinations AI Agent"}

# Clear old sessions periodically (simple implementation)
@app.on_event("startup")
async def startup_event():
    logger.info("Pollinations AI Agent started successfully!")

if __name__ == "__main__":
    port = int(os.environ.get("PORT", 8000))
    uvicorn.run(app, host="0.0.0.0", port=port)
