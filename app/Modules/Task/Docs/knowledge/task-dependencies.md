Tasks can be nested and can depend on other tasks.

Use a child task when the work is a real separate responsibility with its own
assignee, status, estimate, or activity.

Use a checklist item when the step is only a small reminder inside the task and
does not need separate ownership or time tracking.

Dependencies are explicit. A task can be blocked by a parent, child, sibling, or
unrelated task. The beta rules support two dependency types:

- Blocks start: the task should not begin until the other task is done.
- Blocks completion: the task cannot be completed until the other task is done.

When a task has unfinished required dependencies, completion is blocked. When a
task has unfinished child tasks, parent completion is also blocked.

This allows task trees to be used as repeatable job plans without losing control
over the order of work.
