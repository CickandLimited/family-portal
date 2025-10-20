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
from app.core.device import ensure_device_cookie

app = FastAPI(title="Family Task Portal")
app.add_middleware(SessionMiddleware, secret_key=settings.session_secret)

app.mount("/static", StaticFiles(directory="app/static"), name="static")

templates = Jinja2Templates(directory="app/templates")


@app.middleware("http")
async def device_cookie_middleware(request: Request, call_next):
    """Ensure each device interacting with the app has an identifying cookie."""
    response: Response = await call_next(request)
    ensure_device_cookie(request, response)
    return response


app.include_router(public_router)
app.include_router(review_router)
app.include_router(admin_router)
app.include_router(uploads_router)


@app.get("/health")
def health() -> dict[str, bool]:
    """Basic liveness probe endpoint."""
    return {"ok": True}
