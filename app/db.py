"""Подключение к БД и сессии SQLAlchemy."""
from __future__ import annotations

import contextlib
from sqlalchemy import create_engine, event
from sqlalchemy.orm import sessionmaker, DeclarativeBase

from . import config


class Base(DeclarativeBase):
    pass


_connect_args = {}
if config.DB_URL.startswith("sqlite"):
    _connect_args = {"check_same_thread": False, "timeout": 30}

engine = create_engine(
    config.DB_URL,
    future=True,
    pool_pre_ping=True,
    connect_args=_connect_args,
)

if config.DB_URL.startswith("sqlite"):

    @event.listens_for(engine, "connect")
    def _sqlite_pragmas(dbapi_conn, _rec):  # pragma: no cover - инфраструктура
        cur = dbapi_conn.cursor()
        cur.execute("PRAGMA journal_mode=WAL")
        cur.execute("PRAGMA synchronous=NORMAL")
        cur.execute("PRAGMA foreign_keys=ON")
        cur.execute("PRAGMA busy_timeout=30000")
        cur.close()


SessionLocal = sessionmaker(bind=engine, autoflush=False, expire_on_commit=False, future=True)


@contextlib.contextmanager
def session_scope():
    """Транзакция: коммит при успехе, откат при ошибке."""
    s = SessionLocal()
    try:
        yield s
        s.commit()
    except Exception:
        s.rollback()
        raise
    finally:
        s.close()


def get_session():
    """FastAPI-зависимость."""
    s = SessionLocal()
    try:
        yield s
    finally:
        s.close()


def init_db():
    from . import models  # noqa: F401  (регистрация моделей)

    Base.metadata.create_all(engine)
    ensure_columns()


def ensure_columns() -> list[str]:
    """Простая миграция: добавляет колонки, появившиеся в моделях после создания таблиц."""
    from sqlalchemy import inspect, text

    # Без импорта моделей Base.metadata пуст, и функция молча ничего не мигрирует.
    # init_db импортирует их сам, но вызывать ensure_columns можно и отдельно.
    from . import models  # noqa: F401

    inspector = inspect(engine)
    added: list[str] = []
    existing_tables = set(inspector.get_table_names())
    with engine.begin() as conn:
        for table in Base.metadata.sorted_tables:
            if table.name not in existing_tables:
                continue
            have = {col["name"] for col in inspector.get_columns(table.name)}
            for column in table.columns:
                if column.name in have:
                    continue
                col_type = column.type.compile(engine.dialect)
                default = ""
                if column.default is not None and getattr(column.default, "is_scalar", False):
                    value = column.default.arg
                    if isinstance(value, bool):
                        value = 1 if value else 0
                    if isinstance(value, str):
                        value = "'" + value.replace("'", "''") + "'"
                    default = f" DEFAULT {value}"
                conn.execute(text(
                    f'ALTER TABLE {table.name} ADD COLUMN {column.name} {col_type}{default}'))
                added.append(f"{table.name}.{column.name}")
    if added:
        import logging

        logging.getLogger("cf.db").info("Схема дополнена колонками: %s", ", ".join(added))
    return added
