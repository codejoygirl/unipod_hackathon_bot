"""RBAC and Tenant Isolation Policies."""

from fastapi import HTTPException, Security
from fastapi.security import HTTPBearer, HTTPAuthorizationCredentials
import uuid
import logging

logger = logging.getLogger(__name__)
security = HTTPBearer()

def verify_tenant_boundary(request_tenant_id: uuid.UUID, token_tenant_id: uuid.UUID):
    """Enforces strict tenant isolation. Fails immediately if mismatched."""
    if request_tenant_id != token_tenant_id:
        logger.error(f"Tenant isolation breach attempt! {request_tenant_id} != {token_tenant_id}")
        raise HTTPException(status_code=403, detail="Cross-tenant access forbidden.")

async def extract_token_claims(credentials: HTTPAuthorizationCredentials = Security(security)) -> dict:
    """Mock extractor for JWT claims. In a real system, this decodes the JWT using JWKS."""
    # For testing, we mock token decoding
    return {
        "tenant_id": "19333900-de82-487b-beeb-5b959660425b",
        "roles": ["community_member", "admin"]
    }

def require_roles(allowed_roles: list[str]):
    """Dependency that enforces RBAC roles."""
    async def role_checker(claims: dict = Security(extract_token_claims)):
        user_roles = set(claims.get("roles", []))
        if not set(allowed_roles).intersection(user_roles):
            raise HTTPException(status_code=403, detail="Insufficient permissions.")
        return claims
    return role_checker
