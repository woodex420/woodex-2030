import { useState } from "react";
import { Link } from "react-router";
import { PageHeader } from "@/components/layout/PageHeader";
import { Avatar, AvatarGroup } from "@/components/ui/Avatar";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { Button, IconButton } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { Dropdown, DropdownDivider, DropdownItem, DropdownLabel } from "@/components/ui/Dropdown";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { Progress } from "@/components/ui/Progress";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Switch } from "@/components/ui/Switch";
import { Tabs } from "@/components/ui/Tabs";
import { useToast } from "@/components/ui/Toast";
import { ArrowRight, Bell, Check, ChevronDown, Plus, Sparkles, X } from "@/icons";

type PreviewRow = { id: number; component: string; status: string; owner: string };

const previewRows: PreviewRow[] = [
  { id: 1, component: "Primary button", status: "Approved", owner: "Amina" },
  { id: 2, component: "Status badge", status: "In Progress", owner: "Bilal" },
  { id: 3, component: "Form controls", status: "Pending", owner: "Hira" },
  { id: 4, component: "Modal dialog", status: "Approved", owner: "Omar" },
  { id: 5, component: "Empty state", status: "In Progress", owner: "Sara" },
  { id: 6, component: "Data table", status: "Pending", owner: "Usman" },
];

const previewColumns: Column<PreviewRow>[] = [
  { key: "component", header: "Component", cell: (row) => <span className="text-small font-medium text-ink">{row.component}</span> },
  { key: "status", header: "Status", cell: (row) => <StatusBadge status={row.status} /> },
  { key: "owner", header: "Owner", cell: (row) => <span className="text-small text-muted">{row.owner}</span> },
];

export default function UIComponents() {
  const { push } = useToast();
  const [dialogOpen, setDialogOpen] = useState(false);
  const [loadingPreview, setLoadingPreview] = useState(false);
  const [switchOn, setSwitchOn] = useState(true);
  const [tab, setTab] = useState("overview");
  const [dismissedAlert, setDismissedAlert] = useState(false);

  const simulateLoading = () => {
    if (loadingPreview) return;
    setLoadingPreview(true);
    window.setTimeout(() => setLoadingPreview(false), 1100);
  };

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Insights", to: "/analytics" }, { label: "UI Components" }]}
        title="UI Components"
        description="A working catalogue of the shared Woodex dashboard patterns. Components keep the existing green, charcoal and warm-neutral theme."
        actions={<Button type="button" variant="secondary" onClick={() => push({ tone: "info", title: "Component catalogue", desc: "Shared UI primitives are ready to use across dashboard pages." })}><Sparkles size={14} /> What's included</Button>}
      />

      <div className="mb-5 grid gap-3 sm:grid-cols-3">
        {[
          { label: "Theme", value: "Woodex Green", detail: "Primary action and focus color" },
          { label: "Surface", value: "Warm white", detail: "Cards, forms and overlays" },
          { label: "Interaction", value: "Keyboard ready", detail: "Visible focus and clear disabled states" },
        ].map((item) => (
          <Card key={item.label} className="p-4">
            <p className="text-caption font-medium text-muted">{item.label}</p>
            <p className="mt-1 text-bodylg font-semibold text-ink">{item.value}</p>
            <p className="mt-0.5 text-caption text-subtle">{item.detail}</p>
          </Card>
        ))}
      </div>

      <div className="grid gap-4 xl:grid-cols-2">
        <Card>
          <CardHeader title="Buttons" description="Primary actions, quiet actions and destructive actions." />
          <div className="flex flex-wrap items-center gap-2">
            <Button type="button" onClick={() => push({ tone: "success", title: "Primary action", desc: "The action completed successfully." })}><Check size={14} /> Primary</Button>
            <Button type="button" variant="secondary">Secondary</Button>
            <Button type="button" variant="subtle">Subtle</Button>
            <Button type="button" variant="ghost">Ghost</Button>
            <Button type="button" variant="danger"><X size={14} /> Destructive</Button>
          </div>
          <div className="mt-5 flex flex-wrap items-center gap-3 border-t border-line pt-4">
            <div className="flex flex-wrap items-center gap-2">
              <Button type="button" size="sm">Small</Button>
              <Button type="button">Medium</Button>
              <Button type="button" size="lg">Large</Button>
            </div>
            <Button type="button" variant="secondary" onClick={simulateLoading}>Try loading</Button>
            <Button type="button" loading={loadingPreview}>{loadingPreview ? "Saving…" : "Loading state"}</Button>
            <Button type="button" variant="secondary" disabled>Disabled</Button>
            <IconButton label="Add item"><Plus size={16} /></IconButton>
          </div>
          <p className="mt-3 text-caption text-subtle">Tab to any control to see the high-contrast keyboard focus ring. Loading buttons disable themselves until the action finishes.</p>
        </Card>

        <Card>
          <CardHeader title="Badges & avatars" description="Status and priority always pair color with a readable label." />
          <div className="flex flex-wrap items-center gap-2">
            <StatusBadge status="New" />
            <StatusBadge status="In Progress" />
            <StatusBadge status="Approved" />
            <StatusBadge status="Overdue" />
            <Badge tone="warning">Needs review</Badge>
            <Badge tone="primary" dot={false}>New message</Badge>
          </div>
          <div className="mt-5 flex flex-wrap items-center gap-5 border-t border-line pt-4">
            <div className="flex items-center gap-2"><Avatar initials="AM" size="sm" /><Avatar initials="BK" /><Avatar initials="HT" size="lg" /></div>
            <div className="flex items-center gap-3"><AvatarGroup names={["AM", "BK", "HT", "OS", "SR"]} /><span className="text-small text-muted">Project team · 5 members</span></div>
          </div>
        </Card>

        <Card>
          <CardHeader title="Alerts & notifications" description="Inline feedback for important changes and short-lived confirmations." />
          <div className="space-y-2">
            {!dismissedAlert ? (
              <div role="status" className="flex items-start gap-2.5 rounded-control border border-info/25 bg-info-soft/60 p-3 text-small text-info-strong">
                <Bell size={15} className="mt-0.5 shrink-0" />
                <span className="flex-1"><strong className="font-semibold">New update available.</strong> Your catalogue changes are saved as a draft.</span>
                <button type="button" aria-label="Dismiss alert" onClick={() => setDismissedAlert(true)} className="rounded p-0.5 hover:bg-white/70 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-500"><X size={14} /></button>
              </div>
            ) : <Button type="button" size="sm" variant="secondary" onClick={() => setDismissedAlert(false)}>Show inline alert again</Button>}
            <div role="status" className="rounded-control border border-success/25 bg-success-soft/60 px-3 py-2.5 text-small text-success-strong"><strong className="font-semibold">Saved.</strong> Your changes are up to date.</div>
            <div role="alert" className="rounded-control border border-warning/25 bg-warning-soft/60 px-3 py-2.5 text-small text-warning-strong"><strong className="font-semibold">Action needed.</strong> Review the overdue client follow-up.</div>
          </div>
          <div className="mt-4 flex flex-wrap gap-2 border-t border-line pt-4">
            <Button type="button" variant="secondary" onClick={() => push({ tone: "success", title: "Changes saved", desc: "The notification disappears automatically." })}>Show success toast</Button>
            <Button type="button" variant="secondary" onClick={() => push({ tone: "danger", title: "Could not save", desc: "Please retry the action." })}>Show error toast</Button>
          </div>
        </Card>

        <Card>
          <CardHeader title="Form controls" description="Consistent labels, helper text, focus styles and native input behavior." />
          <div className="grid gap-3 sm:grid-cols-2">
            <Field label="Name" required><Input placeholder="Your name" defaultValue="Amina Malik" /></Field>
            <Field label="Status" hint="Choose the current review stage."><Select defaultValue="In Progress"><option>New</option><option>In Progress</option><option>Approved</option></Select></Field>
            <Field label="Notes" className="sm:col-span-2"><Textarea placeholder="Add a short note…" /></Field>
          </div>
          <div className="mt-3 flex items-center gap-3 border-t border-line pt-4">
            <Switch checked={switchOn} onChange={setSwitchOn} label="Enable catalogue notifications" />
            <span className="text-small text-muted">Notifications {switchOn ? "enabled" : "paused"}</span>
          </div>
        </Card>

        <Card>
          <CardHeader title="Tabs & button groups" description="Use a compact pill group for related local views." />
          <Tabs variant="pill" value={tab} onChange={setTab} items={[
            { value: "overview", label: "Overview" },
            { value: "activity", label: "Activity", count: 4 },
            { value: "settings", label: "Settings" },
          ]} />
          <div className="mt-4 rounded-control border border-line bg-slate-50 p-4">
            <p className="text-small font-semibold text-ink">{tab[0].toUpperCase() + tab.slice(1)} panel</p>
            <p className="mt-1 text-small text-muted">Only the selected view is emphasized; the surrounding Woodex palette stays consistent.</p>
          </div>
          <div className="mt-4 flex items-center gap-2 border-t border-line pt-4">
            <span className="text-caption font-medium text-subtle">Breadcrumb:</span>
            <nav aria-label="Component breadcrumb" className="flex items-center gap-2 text-small">
              <Link to="/" className="text-muted hover:text-primary-700">Overview</Link><span className="text-subtle">/</span><span aria-current="page" className="font-medium text-ink">Components</span>
            </nav>
          </div>
        </Card>

        <Card>
          <CardHeader title="Dropdowns & progress" description="Small action menus and accessible completion indicators." action={
            <Dropdown trigger={(open) => <Button type="button" variant="secondary" size="sm" aria-haspopup="menu" aria-expanded={open}>Actions <ChevronDown size={13} /></Button>}>
              <DropdownLabel>Quick actions</DropdownLabel>
              <DropdownItem onClick={() => push({ tone: "info", title: "Preview opened" })}><Sparkles size={14} /> Preview</DropdownItem>
              <DropdownItem onClick={() => push({ tone: "success", title: "Link copied" })}><ArrowRight size={14} /> Copy link</DropdownItem>
              <DropdownDivider />
              <DropdownItem tone="danger" onClick={() => push({ tone: "warning", title: "Delete is a demo" })}><X size={14} /> Remove</DropdownItem>
            </Dropdown>
          } />
          <div className="space-y-4">
            <div><div className="mb-1.5 flex justify-between text-caption"><span className="font-medium text-ink">Page setup</span><span className="text-muted">72%</span></div><Progress value={72} tone="primary" size="lg" /></div>
            <div><div className="mb-1.5 flex justify-between text-caption"><span className="font-medium text-ink">Content review</span><span className="text-muted">100%</span></div><Progress value={100} tone="success" /></div>
            <div className="flex items-center gap-2"><Button type="button" variant="secondary" title="Opens an explanatory popover" onClick={() => push({ tone: "info", title: "Progress details", desc: "Completion is calculated from the steps on this page." })}>Show progress details</Button><span className="text-caption text-subtle">Hover or focus for the tooltip on the sample button.</span></div>
          </div>
        </Card>

        <Card padded={false} className="overflow-hidden">
          <div className="p-5 pb-0"><CardHeader title="Data table & pagination" description="A reusable table with compact status chips and built-in paging." /></div>
          <DataTable rows={previewRows} columns={previewColumns} perPage={4} />
        </Card>

        <Card>
          <CardHeader title="Cards & media placeholders" description="A few surface treatments for content and page-builder blocks." />
          <div className="grid gap-3 sm:grid-cols-2">
            <div className="overflow-hidden rounded-card border border-line">
              <div className="grid h-28 place-items-center bg-[linear-gradient(140deg,#eff5ea,#dce2dc)] text-primary-700" role="img" aria-label="Soft green image placeholder"><Sparkles size={26} /></div>
              <div className="p-3"><p className="text-small font-semibold text-ink">Image card</p><p className="mt-0.5 text-caption text-muted">A responsive media placeholder.</p></div>
            </div>
            <div className="rounded-card border border-primary-200 bg-primary-50/60 p-3">
              <p className="text-caption font-semibold text-primary-800 uppercase">Feature ribbon</p>
              <p className="mt-2 text-small font-semibold text-ink">Woodex design system</p>
              <p className="mt-1 text-caption text-muted">The card uses green as an accent, not as a new theme.</p>
              <Link to="/tasks" className="mt-3 inline-flex items-center gap-1 text-caption font-semibold text-primary-700 hover:text-primary-800">Open task screen <ArrowRight size={13} /></Link>
            </div>
          </div>
        </Card>

        <Card>
          <CardHeader title="Loading, empty & error states" description="Give each API-backed screen a clear way through waiting, no-data and retry states." />
          <div className="grid gap-4 md:grid-cols-3">
            <div className="rounded-card border border-line p-3"><p className="mb-3 text-caption font-semibold text-subtle uppercase">Loading</p><div className="space-y-2"><Skeleton className="h-4 w-2/3" /><Skeleton className="h-8 w-full" /><Skeleton className="h-8 w-5/6" /></div></div>
            <div className="rounded-card border border-line"><EmptyState icon={<Sparkles size={17} />} title="No drafts yet" description="Your new drafts will appear here." className="px-3 py-5" /></div>
            <div className="rounded-card border border-line p-3"><ErrorState title="Preview unavailable" description="Check the connection and try again." onRetry={() => push({ tone: "info", title: "Retry requested" })} className="h-full px-3 py-5" /></div>
          </div>
        </Card>

        <Card className="xl:col-span-2">
          <CardHeader title="Modal dialog" description="Use an overlay for a short, focused decision or form." action={<Button type="button" onClick={() => setDialogOpen(true)}>Open sample modal</Button>} />
          <p className="text-small text-muted">Escape and the close button dismiss the sample. The modal pattern is also used by the task-creation form.</p>
        </Card>
      </div>

      <Modal
        open={dialogOpen}
        onClose={() => setDialogOpen(false)}
        title="Review component changes"
        description="This is a preview of the shared modal primitive."
        footer={<><Button type="button" variant="secondary" onClick={() => setDialogOpen(false)}>Cancel</Button><Button type="button" onClick={() => { setDialogOpen(false); push({ tone: "success", title: "Review confirmed" }); }}>Confirm</Button></>}
      >
        <p className="text-small leading-relaxed text-muted">Use dialogs for one focused task. Keep the action label specific, provide a clear way to cancel, and avoid nesting multiple overlays.</p>
      </Modal>
    </div>
  );
}
