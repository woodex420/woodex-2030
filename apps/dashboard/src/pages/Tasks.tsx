import { useMemo, useState } from "react";
import type { DragEvent, FormEvent } from "react";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Avatar } from "@/components/ui/Avatar";
import { Badge } from "@/components/ui/Badge";
import { Button, IconButton } from "@/components/ui/Button";
import { Card } from "@/components/ui/Card";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { Field, Input, SearchInput, Select } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { SkeletonRows, EmptyState, ErrorState } from "@/components/ui/States";
import { Tabs } from "@/components/ui/Tabs";
import { useToast } from "@/components/ui/Toast";
import { mutate, useApi, useRealtime } from "@/lib/api";
import { Calendar, Check, CheckCircle, ChevronLeft, ChevronRight, ClipboardList, Clock, Kanban, Plus, RefreshCw } from "@/icons";

type Task = {
  id: number;
  clientId: number | null;
  leadId: number | null;
  quoteId: number | null;
  orderId: number | null;
  title: string;
  due: string | null;
  owner: string;
  priority: string;
  done: boolean;
  createdAt?: string;
  updatedAt?: string;
};

type TasksResponse = { total: number; open: number; overdue: number; items: Task[] };
type ClientOption = { id: number; name: string; company?: string | null };
type ClientsResponse = { total: number; items: ClientOption[] };
type View = "list" | "board" | "calendar";
type ListFilter = "all" | "open" | "done";
type TaskDraft = { title: string; due: string; owner: string; priority: string; clientId: string };

const blankDraft = (): TaskDraft => ({ title: "", due: "", owner: "You", priority: "normal", clientId: "" });
const weekdays = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];

function localDay(date: Date) {
  const pad = (value: number) => String(value).padStart(2, "0");
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

function formatDue(due: string | null) {
  if (!due) return "No due date";
  const [year, month, day] = due.slice(0, 10).split("-").map(Number);
  if (!year || !month || !day) return due;
  return new Date(year, month - 1, day).toLocaleDateString("en-PK", { day: "numeric", month: "short", year: "numeric" });
}

function initials(name: string) {
  return name.trim().split(/\s+/).slice(0, 2).map((part) => part[0] ?? "").join("").toUpperCase() || "?";
}

function priorityTone(priority: string): "danger" | "warning" | "neutral" | "primary" {
  if (priority.toLowerCase() === "high") return "danger";
  if (priority.toLowerCase() === "low") return "neutral";
  if (priority.toLowerCase() === "medium") return "warning";
  return "primary";
}

function relatedRecord(task: Task, clients: Map<number, string>) {
  if (task.clientId) return clients.get(task.clientId) ?? `Client #${task.clientId}`;
  if (task.orderId) return `Order #${task.orderId}`;
  if (task.quoteId) return `Quote #${task.quoteId}`;
  if (task.leadId) return `Lead #${task.leadId}`;
  return "No linked record";
}

export default function Tasks() {
  const { push } = useToast();
  const tasks = useApi<TasksResponse>("/api/tasks", 30000);
  const clients = useApi<ClientsResponse>("/api/clients", 60000);
  useRealtime(/tasks|clients/, () => { tasks.reload(); clients.reload(); });

  const [view, setView] = useState<View>("list");
  const [listFilter, setListFilter] = useState<ListFilter>("all");
  const [query, setQuery] = useState("");
  const [month, setMonth] = useState(() => {
    const today = new Date();
    return new Date(today.getFullYear(), today.getMonth(), 1);
  });
  const [createOpen, setCreateOpen] = useState(false);
  const [draft, setDraft] = useState<TaskDraft>(blankDraft);
  const [saving, setSaving] = useState(false);
  const [pendingIds, setPendingIds] = useState<Set<number>>(() => new Set());
  const [draggingId, setDraggingId] = useState<number | null>(null);

  const items = tasks.data?.items ?? [];
  const today = localDay(new Date());
  const openTasks = items.filter((task) => !task.done);
  const doneTasks = items.filter((task) => task.done);
  const overdueTasks = openTasks.filter((task) => task.due && task.due.slice(0, 10) < today);
  const dueTodayTasks = openTasks.filter((task) => task.due?.slice(0, 10) === today);
  const clientNames = useMemo(() => new Map((clients.data?.items ?? []).map((client) => [client.id, client.name])), [clients.data]);
  const searchedTasks = useMemo(() => {
    const needle = query.trim().toLowerCase();
    if (!needle) return items;
    return items.filter((task) => [task.title, task.owner, task.priority, task.due ?? "", relatedRecord(task, clientNames)].some((value) => value.toLowerCase().includes(needle)));
  }, [items, query, clientNames]);
  const listedTasks = useMemo(() => searchedTasks.filter((task) => listFilter === "all" || (listFilter === "done" ? task.done : !task.done)), [searchedTasks, listFilter]);

  const monthDays = useMemo(() => {
    const firstDay = new Date(month.getFullYear(), month.getMonth(), 1);
    const mondayOffset = (firstDay.getDay() + 6) % 7;
    const gridStart = new Date(month.getFullYear(), month.getMonth(), 1 - mondayOffset);
    return Array.from({ length: 42 }, (_, index) => new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + index));
  }, [month]);
  const tasksByDue = useMemo(() => {
    const grouped = new Map<string, Task[]>();
    for (const task of searchedTasks) {
      if (!task.due) continue;
      const key = task.due.slice(0, 10);
      const group = grouped.get(key) ?? [];
      group.push(task);
      grouped.set(key, group);
    }
    return grouped;
  }, [searchedTasks]);
  const unscheduledTasks = searchedTasks.filter((task) => !task.due);

  const startCreate = (due = "") => {
    setDraft({ ...blankDraft(), due });
    setCreateOpen(true);
  };

  const toggleDone = async (task: Task, done: boolean) => {
    setPendingIds((current) => new Set(current).add(task.id));
    try {
      await mutate(`/api/tasks/${task.id}`, { done });
      push({ tone: "success", title: done ? "Task completed" : "Task reopened", desc: task.title });
      await tasks.reload();
    } catch (error) {
      push({ tone: "danger", title: "Could not update task", desc: error instanceof Error ? error.message : String(error) });
    } finally {
      setPendingIds((current) => {
        const next = new Set(current);
        next.delete(task.id);
        return next;
      });
    }
  };

  const createTask = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const title = draft.title.trim();
    if (!title) return;
    setSaving(true);
    try {
      await mutate("/api/tasks", {
        title,
        due: draft.due || null,
        owner: draft.owner.trim() || "You",
        priority: draft.priority,
        client_id: draft.clientId ? Number(draft.clientId) : null,
      }, "POST");
      push({ tone: "success", title: "Task created", desc: title });
      setCreateOpen(false);
      setDraft(blankDraft());
      await tasks.reload();
    } catch (error) {
      push({ tone: "danger", title: "Could not create task", desc: error instanceof Error ? error.message : String(error) });
    } finally {
      setSaving(false);
    }
  };

  const onDrop = (event: DragEvent<HTMLElement>, done: boolean) => {
    event.preventDefault();
    const id = Number(event.dataTransfer.getData("text/plain"));
    const task = items.find((candidate) => candidate.id === id);
    if (task && task.done !== done) void toggleDone(task, done);
    setDraggingId(null);
  };

  const columns: Column<Task>[] = [
    {
      key: "task",
      header: "Task",
      className: "min-w-[240px]",
      cell: (task) => (
        <div className="flex items-center gap-3">
          <span
            aria-hidden="true"
            className={cn(
              "grid h-7 w-7 shrink-0 place-items-center rounded-full border",
              task.done ? "border-success bg-success text-white" : "border-line-strong bg-white text-transparent"
            )}
          >
            <Check size={14} />
          </span>
          <span className="min-w-0">
            <span className={cn("block max-w-[360px] truncate text-small font-semibold", task.done ? "text-muted line-through" : "text-ink")}>{task.title}</span>
            <span className="block truncate text-caption text-subtle">{relatedRecord(task, clientNames)}</span>
          </span>
        </div>
      ),
    },
    {
      key: "due",
      header: "Due date",
      cell: (task) => {
        if (task.done) return <Badge tone="success" dot={false}>Completed{task.due ? ` · ${formatDue(task.due)}` : ""}</Badge>;
        if (!task.due) return <span className="text-caption text-subtle">No due date</span>;
        if (task.due.slice(0, 10) < today) return <Badge tone="danger" dot={false}>Overdue · {formatDue(task.due)}</Badge>;
        if (task.due.slice(0, 10) === today) return <Badge tone="warning" dot={false}>Due today</Badge>;
        return <span className="text-small text-muted">{formatDue(task.due)}</span>;
      },
    },
    {
      key: "owner",
      header: "Owner",
      cell: (task) => (
        <span className="inline-flex items-center gap-2 text-small text-muted">
          <Avatar initials={initials(task.owner || "You")} size="xs" />
          {task.owner || "You"}
        </span>
      ),
    },
    {
      key: "priority",
      header: "Priority",
      cell: (task) => <Badge tone={priorityTone(task.priority)} dot={false}>{task.priority || "Normal"}</Badge>,
    },
    {
      key: "action",
      header: "Action",
      className: "w-28",
      cell: (task) => (
        <Button
          type="button"
          size="sm"
          variant={task.done ? "secondary" : "subtle"}
          loading={pendingIds.has(task.id)}
          onClick={() => void toggleDone(task, !task.done)}
        >
          {pendingIds.has(task.id) ? "Saving" : task.done ? "Reopen" : "Complete"}
        </Button>
      ),
    },
  ];

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Workspace", to: "/" }, { label: "Tasks" }]}
        title="Tasks & follow-ups"
        description="Keep client follow-ups moving. Tasks are shared with the CRM and use the existing open/done workflow."
        actions={
          <>
            <Button type="button" variant="secondary" onClick={() => void tasks.reload()}><RefreshCw size={14} /> Refresh</Button>
            <Button type="button" onClick={() => startCreate()}><Plus size={15} /> New task</Button>
          </>
        }
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Card className="flex items-center gap-3 p-4">
          <span className="grid h-10 w-10 place-items-center rounded-control bg-primary-50 text-primary-700"><ClipboardList size={18} /></span>
          <span><span className="block text-caption font-medium text-muted">Open tasks</span><span className="block text-h2 text-ink">{openTasks.length}</span></span>
        </Card>
        <Card className="flex items-center gap-3 p-4">
          <span className="grid h-10 w-10 place-items-center rounded-control bg-warning-soft text-warning-strong"><Clock size={18} /></span>
          <span><span className="block text-caption font-medium text-muted">Due today</span><span className="block text-h2 text-ink">{dueTodayTasks.length}</span></span>
        </Card>
        <Card className="flex items-center gap-3 p-4">
          <span className="grid h-10 w-10 place-items-center rounded-control bg-danger-soft text-danger-strong"><Calendar size={18} /></span>
          <span><span className="block text-caption font-medium text-muted">Overdue</span><span className="block text-h2 text-ink">{overdueTasks.length}</span></span>
        </Card>
        <Card className="flex items-center gap-3 p-4">
          <span className="grid h-10 w-10 place-items-center rounded-control bg-success-soft text-success-strong"><CheckCircle size={18} /></span>
          <span><span className="block text-caption font-medium text-muted">Completed</span><span className="block text-h2 text-ink">{doneTasks.length}</span></span>
        </Card>
      </div>

      <Card padded={false} className="overflow-hidden">
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-line px-4 py-3.5 lg:px-5">
          <Tabs
            value={view}
            onChange={(value) => setView(value as View)}
            variant="pill"
            items={[
              { value: "list", label: "List", icon: <ClipboardList size={14} />, count: items.length },
              { value: "board", label: "Open / done board", icon: <Kanban size={14} /> },
              { value: "calendar", label: "Due-date calendar", icon: <Calendar size={14} /> },
            ]}
          />
          <SearchInput value={query} onChange={setQuery} placeholder="Search tasks, owner, client…" className="w-full sm:w-64" />
        </div>

        {tasks.error && tasks.data && (
          <div role="status" className="border-b border-warning/30 bg-warning-soft/40 px-4 py-2 text-caption text-warning-strong">
            Showing the latest loaded tasks. Refresh failed: {tasks.error}
          </div>
        )}

        {tasks.loading && !tasks.data ? (
          <div className="p-4"><SkeletonRows rows={6} /></div>
        ) : tasks.error && !tasks.data ? (
          <div className="p-4"><ErrorState title="Tasks unavailable" description="The task list could not be loaded from the workspace API." debug="GET /api/tasks" onRetry={() => void tasks.reload()} /></div>
        ) : view === "list" ? (
          <>
            <div className="border-b border-line px-4 pt-2 lg:px-5">
              <Tabs
                value={listFilter}
                onChange={(value) => setListFilter(value as ListFilter)}
                items={[
                  { value: "all", label: "All tasks", count: searchedTasks.length },
                  { value: "open", label: "Open", count: searchedTasks.filter((task) => !task.done).length },
                  { value: "done", label: "Done", count: searchedTasks.filter((task) => task.done).length },
                ]}
              />
            </div>
            {listedTasks.length === 0 ? (
              <EmptyState
                icon={<ClipboardList size={20} />}
                title={query ? "No matching tasks" : listFilter === "done" ? "No completed tasks yet" : "You're all caught up"}
                description={query ? "Try a different search, or clear the query to see all tasks." : "Create a task to start tracking the next follow-up."}
                action={!query && listFilter !== "done" ? <Button type="button" size="sm" onClick={() => startCreate()}><Plus size={13} /> New task</Button> : undefined}
              />
            ) : (
              <DataTable key={`${listFilter}-${query}`} rows={listedTasks} columns={columns} perPage={10} />
            )}
          </>
        ) : view === "board" ? (
          <div className="grid gap-4 p-4 lg:grid-cols-2 lg:p-5">
            {([
              { done: false, title: "Open", lane: openTasks.filter((task) => searchedTasks.some((match) => match.id === task.id)) },
              { done: true, title: "Done", lane: doneTasks.filter((task) => searchedTasks.some((match) => match.id === task.id)) },
            ] as const).map(({ done, title, lane }) => (
              <section
                key={title}
                aria-label={`${title} tasks drop zone`}
                onDragOver={(event) => event.preventDefault()}
                onDrop={(event) => onDrop(event, done)}
                className={cn("min-h-72 rounded-card border p-3 transition-colors", draggingId !== null && "border-dashed border-primary-400 bg-primary-50/40", draggingId === null && "border-line bg-slate-50/70")}
              >
                <div className="mb-3 flex items-center justify-between gap-3 px-1">
                  <div className="flex items-center gap-2"><h2 className="text-small font-semibold text-ink">{title}</h2><Badge tone={done ? "success" : "primary"} dot={false}>{lane.length}</Badge></div>
                  <span className="text-caption text-subtle">Drop a card to {done ? "complete" : "reopen"}</span>
                </div>
                {lane.length ? (
                  <div className="space-y-2.5">
                    {lane.map((task) => (
                      <article
                        key={task.id}
                        draggable
                        onDragStart={(event) => {
                          event.dataTransfer.effectAllowed = "move";
                          event.dataTransfer.setData("text/plain", String(task.id));
                          setDraggingId(task.id);
                        }}
                        onDragEnd={() => setDraggingId(null)}
                        className={cn("rounded-card border border-line bg-white p-3.5 shadow-card transition-shadow hover:shadow-pop", draggingId === task.id && "opacity-50", pendingIds.has(task.id) && "opacity-60")}
                      >
                        <div className="flex items-start justify-between gap-3">
                          <div className="min-w-0">
                            <h3 className={cn("text-small font-semibold", task.done ? "text-muted line-through" : "text-ink")}>{task.title}</h3>
                            <p className="mt-1 truncate text-caption text-subtle">{relatedRecord(task, clientNames)}</p>
                          </div>
                          <Badge tone={priorityTone(task.priority)} dot={false}>{task.priority || "Normal"}</Badge>
                        </div>
                        <div className="mt-3 flex flex-wrap items-center justify-between gap-2 border-t border-slate-100 pt-2.5">
                          <span className="inline-flex items-center gap-1.5 text-caption text-muted">
                            <Avatar initials={initials(task.owner || "You")} size="xs" />
                            {task.owner || "You"} · {task.due ? formatDue(task.due) : "No due date"}
                          </span>
                          <Button type="button" size="sm" variant={task.done ? "secondary" : "subtle"} loading={pendingIds.has(task.id)} onClick={() => void toggleDone(task, !task.done)}>
                            {pendingIds.has(task.id) ? "Saving" : task.done ? "Reopen" : "Complete"}
                          </Button>
                        </div>
                      </article>
                    ))}
                  </div>
                ) : (
                  <div className="grid min-h-48 place-items-center rounded-control border border-dashed border-line-strong bg-white/60 px-5 text-center text-small text-subtle">
                    {query ? "No matching tasks in this lane." : `No ${title.toLowerCase()} tasks.`}
                  </div>
                )}
              </section>
            ))}
            <p className="text-caption text-subtle lg:col-span-2">This board reflects the API's current Open / Done flag. An in-progress status can be added when the task model supports it.</p>
          </div>
        ) : (
          <div className="p-4 lg:p-5">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-3">
              <div>
                <h2 className="text-h3">{month.toLocaleDateString("en-PK", { month: "long", year: "numeric" })}</h2>
                <p className="mt-0.5 text-caption text-muted">Tasks are placed by due date. Select + in a day to schedule a follow-up.</p>
              </div>
              <div className="flex items-center gap-1">
                <IconButton label="Previous month" onClick={() => setMonth((current) => new Date(current.getFullYear(), current.getMonth() - 1, 1))}><ChevronLeft size={16} /></IconButton>
                <Button type="button" size="sm" variant="secondary" onClick={() => setMonth(new Date(new Date().getFullYear(), new Date().getMonth(), 1))}>Today</Button>
                <IconButton label="Next month" onClick={() => setMonth((current) => new Date(current.getFullYear(), current.getMonth() + 1, 1))}><ChevronRight size={16} /></IconButton>
              </div>
            </div>
            <div className="scrollbar-slim overflow-x-auto rounded-card border border-line">
              <div className="min-w-[760px]">
                <div className="grid grid-cols-7 border-b border-line bg-slate-50">
                  {weekdays.map((day) => <div key={day} className="px-3 py-2 text-caption font-semibold text-subtle">{day}</div>)}
                </div>
                <div className="grid grid-cols-7">
                  {monthDays.map((day) => {
                    const dayKey = localDay(day);
                    const dayTasks = tasksByDue.get(dayKey) ?? [];
                    const inMonth = day.getMonth() === month.getMonth();
                    return (
                      <div key={dayKey} className={cn("min-h-32 border-b border-r border-line p-2 last:border-r-0", !inMonth && "bg-slate-50/70", dayKey === today && "bg-primary-50/50")}>
                        <div className="mb-1.5 flex items-center justify-between gap-1">
                          <span className={cn("grid h-6 w-6 place-items-center rounded-full text-caption", dayKey === today ? "bg-primary-600 font-semibold text-white" : inMonth ? "text-ink" : "text-subtle")}>{day.getDate()}</span>
                          <button
                            type="button"
                            aria-label={`Add task due ${formatDue(dayKey)}`}
                            title="Add task on this date"
                            onClick={() => startCreate(dayKey)}
                            className="grid h-6 w-6 place-items-center rounded-control text-subtle transition-colors hover:bg-white hover:text-primary-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"
                          ><Plus size={13} /></button>
                        </div>
                        <div className="space-y-1">
                          {dayTasks.slice(0, 3).map((task) => (
                            <div key={task.id} className={cn("flex min-w-0 items-center gap-1 rounded px-1 py-0.5 text-[10px] leading-4", task.done ? "bg-success-soft/70 text-success-strong" : task.due && task.due.slice(0, 10) < today ? "bg-danger-soft/70 text-danger-strong" : "bg-primary-50 text-primary-800")}>
                              <button
                                type="button"
                                aria-label={task.done ? `Reopen ${task.title}` : `Mark ${task.title} complete`}
                                disabled={pendingIds.has(task.id)}
                                onClick={() => void toggleDone(task, !task.done)}
                                className="grid h-4 w-4 shrink-0 place-items-center rounded-full focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:opacity-50"
                              >{task.done && <Check size={11} />}</button>
                              <span title={task.title} className={cn("min-w-0 truncate", task.done && "line-through")}>{task.title}</span>
                            </div>
                          ))}
                          {dayTasks.length > 3 && <p className="px-1 text-[10px] text-subtle">+{dayTasks.length - 3} more</p>}
                        </div>
                      </div>
                    );
                  })}
                </div>
              </div>
            </div>
            <section className="mt-5" aria-labelledby="unscheduled-heading">
              <div className="mb-2 flex items-center justify-between gap-3">
                <div><h3 id="unscheduled-heading" className="text-small font-semibold text-ink">No due date</h3><p className="text-caption text-muted">Unscheduled tasks stay visible here.</p></div>
                <Badge tone="neutral" dot={false}>{unscheduledTasks.length}</Badge>
              </div>
              {unscheduledTasks.length ? (
                <div className="divide-y divide-slate-100 rounded-card border border-line bg-white">
                  {unscheduledTasks.map((task) => (
                    <div key={task.id} className="flex flex-wrap items-center gap-3 px-3 py-2.5">
                      <button type="button" aria-label={task.done ? `Reopen ${task.title}` : `Mark ${task.title} complete`} disabled={pendingIds.has(task.id)} onClick={() => void toggleDone(task, !task.done)} className="grid h-6 w-6 shrink-0 place-items-center rounded-full border border-line-strong text-transparent hover:border-primary-500 hover:text-primary-600 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 disabled:opacity-50">{task.done && <Check size={13} />}</button>
                      <span className={cn("min-w-0 flex-1 truncate text-small font-medium", task.done ? "text-muted line-through" : "text-ink")}>{task.title}</span>
                      <span className="text-caption text-subtle">{task.owner || "You"}</span>
                      <Badge tone={task.done ? "success" : priorityTone(task.priority)} dot={false}>{task.done ? "Done" : task.priority || "Normal"}</Badge>
                    </div>
                  ))}
                </div>
              ) : <p className="rounded-card border border-dashed border-line-strong px-4 py-5 text-center text-small text-subtle">No unscheduled tasks.</p>}
            </section>
          </div>
        )}
      </Card>

      <Modal
        open={createOpen}
        onClose={() => { if (!saving) setCreateOpen(false); }}
        title="Create task"
        description="Add a follow-up to the shared CRM task list."
        footer={
          <>
            <Button type="button" variant="secondary" onClick={() => setCreateOpen(false)} disabled={saving}>Cancel</Button>
            <Button type="submit" form="task-create-form" loading={saving}>Create task</Button>
          </>
        }
      >
        <form id="task-create-form" onSubmit={(event) => void createTask(event)} className="space-y-4">
          <Field label="Task title" required>
            <Input autoFocus required maxLength={240} placeholder="e.g. Confirm site measurements with client" value={draft.title} onChange={(event) => setDraft((current) => ({ ...current, title: event.target.value }))} />
          </Field>
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Due date" hint="Leave blank if this task is unscheduled.">
              <Input type="date" value={draft.due} onChange={(event) => setDraft((current) => ({ ...current, due: event.target.value }))} />
            </Field>
            <Field label="Owner">
              <Input maxLength={80} placeholder="You" value={draft.owner} onChange={(event) => setDraft((current) => ({ ...current, owner: event.target.value }))} />
            </Field>
            <Field label="Priority">
              <Select value={draft.priority} onChange={(event) => setDraft((current) => ({ ...current, priority: event.target.value }))}>
                <option value="low">Low</option>
                <option value="normal">Normal</option>
                <option value="high">High</option>
              </Select>
            </Field>
            <Field label="Client" hint={clients.error ? "Client list is temporarily unavailable." : undefined}>
              <Select value={draft.clientId} onChange={(event) => setDraft((current) => ({ ...current, clientId: event.target.value }))}>
                <option value="">No linked client</option>
                {(clients.data?.items ?? []).map((client) => <option key={client.id} value={client.id}>{client.name}{client.company ? ` · ${client.company}` : ""}</option>)}
              </Select>
            </Field>
          </div>
        </form>
      </Modal>
    </div>
  );
}
