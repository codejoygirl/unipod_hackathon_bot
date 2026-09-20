"""Resilience mechanisms for distributed systems."""

import asyncio
import logging
import random
from typing import Callable, Any

from ai_service.core.config import settings

logger = logging.getLogger(__name__)

class CircuitBreakerError(Exception):
    """Raised when the circuit is open."""
    pass

class CircuitBreaker:
    """Circuit breaker with exponential backoff and jitter."""

    def __init__(
        self,
        failure_threshold: int | None = None,
        recovery_timeout: float = 30.0,
    ):
        self.failure_threshold = failure_threshold or settings.CIRCUIT_BREAKER_FAILURE_THRESHOLD
        self.recovery_timeout = recovery_timeout
        self.failure_count = 0
        self.state = "CLOSED"  # CLOSED, OPEN, HALF_OPEN
        self.last_failure_time = 0.0

    async def execute(self, operation_name: str, coroutine_func: Callable, *args, **kwargs) -> Any:
        """Executes a coroutine wrapped in a circuit breaker and retry loop."""
        
        if self.state == "OPEN":
            if asyncio.get_event_loop().time() - self.last_failure_time > self.recovery_timeout:
                self.state = "HALF_OPEN"
                logger.info(f"Circuit Breaker for {operation_name} entering HALF_OPEN state.")
            else:
                raise CircuitBreakerError(f"Circuit for {operation_name} is OPEN.")

        attempt = 0
        while True:
            try:
                result = await coroutine_func(*args, **kwargs)
                
                if self.state == "HALF_OPEN" or self.failure_count > 0:
                    logger.info(f"Circuit Breaker for {operation_name} resetting to CLOSED.")
                    self.state = "CLOSED"
                    self.failure_count = 0
                    
                return result
                
            except Exception as exc:
                # In production we would filter to specific retryable exceptions
                attempt += 1
                self.failure_count += 1
                self.last_failure_time = asyncio.get_event_loop().time()
                
                if self.failure_count >= self.failure_threshold:
                    self.state = "OPEN"
                    logger.error(f"Circuit Breaker for {operation_name} OPENED due to {type(exc).__name__}.")
                    raise CircuitBreakerError(f"Circuit OPEN after {self.failure_count} failures: {str(exc)}") from exc
                    
                if attempt > settings.MAX_RETRIES:
                    logger.error(f"{operation_name} failed after {attempt} retries.")
                    raise
                    
                backoff = min(settings.MAX_BACKOFF_SECONDS, settings.BASE_BACKOFF_SECONDS * (2 ** (attempt - 1)))
                jitter = random.uniform(0.1, backoff)
                
                logger.warning(
                    f"{operation_name} encountered {type(exc).__name__}. "
                    f"Retrying ({attempt}/{settings.MAX_RETRIES}) after {jitter:.2f}s"
                )
                await asyncio.sleep(jitter)
