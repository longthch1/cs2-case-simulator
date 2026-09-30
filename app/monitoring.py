import time
import os
try:
    import psutil
except ImportError:
    psutil = None
from datetime import datetime
from collections import defaultdict
from app.config import settings

start_time = time.time()

class MetricsCollector:
    def __init__(self):
        self.request_count = defaultdict(int)
        self.status_codes = defaultdict(int)
        self.cases_opened = 0
        self.total_money_spent = 0.0
        self.total_drops_value = 0.0
        self.total_tradeups = 0
        self.response_times = []  # last 100 response times in ms
        self.max_history = 100

    def record_request(self, method: str, path: str, status_code: int, duration_ms: float):
        endpoint_key = f"{method} {path}"
        self.request_count[endpoint_key] += 1
        self.status_codes[status_code] += 1
        
        self.response_times.append(duration_ms)
        if len(self.response_times) > self.max_history:
            self.response_times.pop(0)

    def record_case_open(self, cost: float, won_value: float, count: int = 1):
        self.cases_opened += count
        self.total_money_spent += cost
        self.total_drops_value += won_value

    def record_tradeup(self):
        self.total_tradeups += 1

    def get_system_stats(self):
        uptime_seconds = int(time.time() - start_time)
        process = psutil.Process(os.getpid()) if hasattr(psutil, 'Process') else None
        
        memory_mb = 0.0
        if process:
            try:
                memory_mb = round(process.memory_info().rss / (1024 * 1024), 2)
            except Exception:
                pass
                
        avg_latency = round(sum(self.response_times) / len(self.response_times), 2) if self.response_times else 0.0

        return {
            "uptime_seconds": uptime_seconds,
            "uptime_formatted": f"{uptime_seconds // 3600}h {(uptime_seconds % 3600) // 60}m {uptime_seconds % 60}s",
            "memory_usage_mb": memory_mb,
            "avg_response_time_ms": avg_latency,
            "total_requests": sum(self.request_count.values()),
            "cases_opened": self.cases_opened,
            "total_spent_usd": round(self.total_money_spent, 2),
            "total_won_usd": round(self.total_drops_value, 2),
            "tradeups_completed": self.total_tradeups,
            "status_codes": dict(self.status_codes)
        }

    def generate_prometheus_metrics(self) -> str:
        stats = self.get_system_stats()
        lines = [
            "# HELP cs2_uptime_seconds Process uptime in seconds",
            "# TYPE cs2_uptime_seconds gauge",
            f"cs2_uptime_seconds {stats['uptime_seconds']}",
            "",
            "# HELP cs2_memory_usage_mb Memory RSS in Megabytes",
            "# TYPE cs2_memory_usage_mb gauge",
            f"cs2_memory_usage_mb {stats['memory_usage_mb']}",
            "",
            "# HELP cs2_cases_opened_total Total number of CS2 cases opened",
            "# TYPE cs2_cases_opened_total counter",
            f"cs2_cases_opened_total {self.cases_opened}",
            "",
            "# HELP cs2_money_spent_usd_total Total USD spent opening cases",
            "# TYPE cs2_money_spent_usd_total counter",
            f"cs2_money_spent_usd_total {round(self.total_money_spent, 2)}",
            "",
            "# HELP cs2_drops_value_usd_total Total USD value of dropped skins",
            "# TYPE cs2_drops_value_usd_total counter",
            f"cs2_drops_value_usd_total {round(self.total_drops_value, 2)}",
            "",
            "# HELP cs2_tradeups_total Total number of trade-up contracts executed",
            "# TYPE cs2_tradeups_total counter",
            f"cs2_tradeups_total {self.total_tradeups}",
            "",
            "# HELP cs2_http_requests_total Total number of HTTP requests by status",
            "# TYPE cs2_http_requests_total counter"
        ]
        for status, count in self.status_codes.items():
            lines.append(f'cs2_http_requests_total{{status="{status}"}} {count}')
            
        return "\n".join(lines) + "\n"

metrics = MetricsCollector()
