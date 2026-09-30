import os
import shutil
from fastapi import APIRouter, Response
from app.monitoring import metrics
from app.database import get_db_connection
from app.config import settings

router = APIRouter(tags=["Monitoring & Health"])

@router.get("/api/health")
async def health_check():
    """System health check endpoint for container orchestrators and load balancers."""
    db_status = "ok"
    try:
        with get_db_connection() as conn:
            cursor = conn.cursor()
            cursor.execute("SELECT 1")
            cursor.fetchone()
    except Exception as e:
        db_status = f"unhealthy: {str(e)}"

    # Disk usage
    total, used, free = shutil.disk_usage(settings.DATA_DIR)
    disk_free_gb = round(free / (1024 ** 3), 2)

    sys_stats = metrics.get_system_stats()

    return {
        "status": "healthy" if db_status == "ok" else "degraded",
        "service": settings.PROJECT_NAME,
        "version": settings.VERSION,
        "environment": settings.ENVIRONMENT,
        "database": db_status,
        "disk_free_gb": disk_free_gb,
        "uptime": sys_stats['uptime_formatted'],
        "memory_mb": sys_stats['memory_usage_mb'],
        "avg_latency_ms": sys_stats['avg_response_time_ms']
    }

@router.get("/metrics")
async def prometheus_metrics():
    """Standard Prometheus metrics exposition format."""
    content = metrics.generate_prometheus_metrics()
    return Response(content=content, media_type="text/plain; version=0.0.4; charset=utf-8")
