"""FastAPI application entrypoint for the Family Task Portal."""

from fastapi import FastAPI, Request, Response
from fastapi.staticfiles import StaticFiles
from fastapi.templating import Jinja2Templates
from starlette.middleware.sessions import SessionMiddleware

from app.api.admin import router as admin_router
from app.api.public import router as public_router
from app.api.review import router as review_router
from app.api.uploads import router as uploads_router
from app.core.config import settings
from app.core.device import COOKIE_NAME, ensure_device_cookie

app = FastAPI(title="Family Task Portal")
app.add_middleware(SessionMiddleware, secret_key=settings.session_secret)

app.mount("/static", StaticFiles(directory="app/static"), name="static")

templates = Jinja2Templates(directory="app/templates")


@app.middleware("http")
async def device_cookie_middleware(request: Request, call_next):
    """Ensure each device interacting with the app has an identifying cookie."""
    device, cookie_missing = ensure_device_cookie(request)
    request.state.device = device
    response: Response = await call_next(request)
    if cookie_missing:
        response.set_cookie(
            COOKIE_NAME,
            device.id,
            httponly=True,
            samesite="lax",
            secure=False,
            max_age=60 * 60 * 24 * 365 * 5,
        )
    return response


app.include_router(public_router)
app.include_router(review_router)
app.include_router(admin_router)
app.include_router(uploads_router)


@app.get("/health")
def health() -> dict[str, bool]:
    """Basic liveness probe endpoint."""
    return {"ok": True}
