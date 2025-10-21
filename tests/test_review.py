from app.api.review import can_approve
from app.models.devices import Device
from app.models.plans import Plan, PlanStatus
from app.models.tasks import PlanDay, Subtask, SubtaskStatus
from app.models.users import User


def _build_review_subtask(assignee_id: int) -> Subtask:
    plan = Plan(
        id=1,
        title="Adventure",
        assignee_user_id=assignee_id,
        status=PlanStatus.IN_PROGRESS,
    )
    day = PlanDay(
        id=10,
        plan_id=plan.id,
        day_index=0,
        title="Day 1",
        locked=False,
    )
    subtask = Subtask(
        id=25,
        plan_day_id=day.id,
        order_index=0,
        text="Collect shells",
        xp_value=15,
        status=SubtaskStatus.SUBMITTED,
    )

    plan.days = [day]
    day.plan = plan
    day.subtasks = [subtask]
    subtask.plan_day = day

    return subtask


def test_can_approve_allows_unrelated_actor():
    subtask = _build_review_subtask(assignee_id=7)

    allowed, message = can_approve(subtask, acting_user=None, acting_device=None)

    assert allowed is True
    assert message is None


def test_can_approve_blocks_assignee_user():
    subtask = _build_review_subtask(assignee_id=11)
    acting_user = User(id=11, display_name="Alex", role="user")

    allowed, message = can_approve(subtask, acting_user=acting_user, acting_device=None)

    assert allowed is False
    assert message == "Assignees cannot approve their own submissions."


def test_can_approve_blocks_linked_device():
    subtask = _build_review_subtask(assignee_id=3)
    acting_device = Device(id="dev-1", linked_user_id=3)

    allowed, message = can_approve(subtask, acting_user=None, acting_device=acting_device)

    assert allowed is False
    assert message == "Devices linked to the assignee cannot approve this submission."


def test_can_approve_allows_unlinked_device():
    subtask = _build_review_subtask(assignee_id=4)
    acting_device = Device(id="dev-2", linked_user_id=9)

    allowed, message = can_approve(subtask, acting_user=None, acting_device=acting_device)

    assert allowed is True
    assert message is None
