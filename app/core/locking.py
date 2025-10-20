"""Plan locking helpers."""


def is_day_locked(previous_complete: bool) -> bool:
    """Determine whether a plan day should be locked."""
    return not previous_complete
