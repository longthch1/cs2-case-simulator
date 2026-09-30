import time
import os
from contextlib import asynccontextmanager
from fastapi import FastAPI, Request
from fastapi.middleware.cors import CORSMiddleware
from fastapi.staticfiles import StaticFiles
from fastapi.responses import FileResponse, JSONResponse

from app.config import settings, STATIC_DIR
from app.logging_config import logger
from app.database import init_db
from app.seed_data import seed_cases_and_skins
from app.security import SecurityHeadersMiddleware
from app.monitoring import metrics

# Import routers
from app.routes.auth import router as auth_router
from app.routes.cases import router as cases_router
from app.routes.inventory import router as inventory_router
from app.routes.tradeup import router as tradeup_router
from app.routes.wallet import router as wallet_router
from app.routes.admin import router as admin_router
from app.routes.monitoring import router as monitoring_router
from app.routes.daily import router as daily_router


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup sequence
    logger.info("Starting CS2 Case Opening Simulator Backend...")
    init_db()
    seed_cases_and_skins()
    logger.info("System ready! Admin user: 'admin' (Password: 'admin')")
    yield
    # Shutdown sequence
    logger.info("Shutting down CS2 Case Simulator Backend...")

app = FastAPI(
    title=settings.PROJECT_NAME,
    version=settings.VERSION,
    description="Full-stack CS2 Case Opening Simulator with Provably Fair RNG, Authentication, RBAC, Wallet, and Monitoring.",
    lifespan=lifespan,
    docs_url="/api/docs",
    redoc_url="/api/redoc"
)

# 1. CORS Middleware
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# 2. Security Headers Middleware
app.add_middleware(SecurityHeadersMiddleware)

# 3. Request Logging and Performance Metric Middleware
@app.middleware("http")
async def request_logging_middleware(request: Request, call_next):
    start_time_req = time.time()
    response = await call_next(request)
    duration_ms = (time.time() - start_time_req) * 1000
    
    # Exclude static assets from metric spam
    path = request.url.path
    if path.startswith("/api") or path == "/metrics":
        metrics.record_request(request.method, path, response.status_code, duration_ms)
        logger.info(f"{request.method} {path} - {response.status_code} ({duration_ms:.1f}ms)")
        
    return response

# 4. Global Exception Handler
@app.exception_handler(Exception)
async def global_exception_handler(request: Request, exc: Exception):
    logger.error(f"Unhandled exception on {request.url.path}: {exc}", exc_info=True)
    return JSONResponse(
        status_code=500,
        content={"detail": "An internal server error occurred. Our engineers have been alerted."}
    )

# 5. Include API Routers
app.include_router(auth_router)
app.include_router(cases_router)
app.include_router(inventory_router)
app.include_router(tradeup_router)
app.include_router(wallet_router)
app.include_router(admin_router)
app.include_router(monitoring_router)
app.include_router(daily_router)


# 6. Static files and SPA Root
app.mount("/static", StaticFiles(directory=str(STATIC_DIR)), name="static")

@app.get("/")
async def serve_index():
    index_file = STATIC_DIR / "index.html"
    if index_file.exists():
        return FileResponse(index_file)
    return {"message": "CS2 Case Opening Simulator API is operational. Visit /api/docs for API specification."}
