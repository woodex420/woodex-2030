import { useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { TeamCard } from "@/components/TeamCard";
import { Card, CardHeader } from "@/components/ui/Card";
import { Field, Input, Select } from "@/components/ui/Field";
import { Switch } from "@/components/ui/Switch";
import { Avatar } from "@/components/ui/Avatar";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { useToast } from "@/components/ui/Toast";
import { Check, Plus } from "@/icons";

const swatches = [
  { name: "Primary 500", hex: "#4F9D21", role: "Actions · active nav" },
  { name: "Primary 600", hex: "#43851C", role: "Hover" },
  { name: "Primary 100", hex: "#EAF5E3", role: "Soft selection" },
  { name: "Charcoal 950", hex: "#171B1E", role: "Sidebar" },
  { name: "Slate 200", hex: "#E5E8EA", role: "Borders" },
  { name: "Success", hex: "#2E8B57", role: "Positive state" },
  { name: "Warning", hex: "#D99A24", role: "Needs attention" },
  { name: "Danger", hex: "#D9534F", role: "Destructive" },
  { name: "Info", hex: "#3578B8", role: "Neutral highlight" },
];

export default function Settings() {
  const { push } = useToast();
  const [flags, setFlags] = useState({ approvals: true, aiReview: true, autoSend: false });
  const set = (k: keyof typeof flags) => (v: boolean) => {
    setFlags((f) => ({ ...f, [k]: v }));
    push({ tone: "primary", title: "Setting saved", desc: `Tenant: Woodex Interiors` });
  };

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Workspace", to: "/" }, { label: "Settings" }]}
        title="Settings"
        description="Workspace, team, governance and theme configuration."
      />

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
<TeamCard />
                <Card>
          <CardHeader title="Workspace" description="Multi-tenant site context (§33)" />
          <div className="grid grid-cols-2 gap-4">
            <Field label="Workspace name">
              <Input defaultValue="Woodex Interiors" />
            </Field>
            <Field label="Primary domain">
              <Input defaultValue="woodexinteriors.com" />
            </Field>
            <Field label="Currency">
              <Select defaultValue="PKR — Pakistani Rupee">
                <option>PKR — Pakistani Rupee</option>
                <option>USD — US Dollar</option>
              </Select>
            </Field>
            <Field label="Tax default">
              <Input defaultValue="18% GST" />
            </Field>
          </div>
          <div className="mt-4 flex items-center justify-between rounded-card border border-line bg-slate-50/70 px-4 py-3">
            <div className="flex items-center gap-2.5">
              <Badge tone="success" dot={false}>Pro plan</Badge>
              <span className="text-caption text-muted">3 sites · unlimited quotations</span>
            </div>
            <Button variant="secondary" size="sm" onClick={() => push({ tone: "info", title: "Add site wizard (Phase 4)" })}>
              <Plus size={13} /> Add site
            </Button>
          </div>
        </Card>

        <Card>
          <CardHeader title="Approval & AI governance" description="Sensitive actions stay approval-gated (§25, §26)" />
          <ul className="divide-y divide-slate-100">
            {[
              { k: "approvals" as const, title: "Quotations require Finance approval", desc: "Draft → Review → Approval → Send" },
              { k: "aiReview" as const, title: "AI drafts require human review", desc: "AI suggestions are never auto-applied or auto-sent" },
              { k: "autoSend" as const, title: "Auto-send approved quotations", desc: "Send immediately when final approval lands" },
            ].map((row) => (
              <li key={row.k} className="flex items-center justify-between gap-4 py-3.5">
                <div className="min-w-0">
                  <p className="text-small font-semibold text-ink">{row.title}</p>
                  <p className="mt-0.5 text-caption text-muted">{row.desc}</p>
                </div>
                <Switch checked={flags[row.k]} onChange={set(row.k)} label={row.title} />
              </li>
            ))}
          </ul>
          <p className="mt-3 flex items-center gap-1.5 text-caption text-subtle">
            <Check size={13} className="text-success-strong" /> Governance events are written to the audit trail.
          </p>
        </Card>

        <Card>
          <CardHeader title="Team & roles" description="RBAC — role per workspace" />
          <ul className="divide-y divide-slate-100">
            {[
              { n: "Ayesha Rehman", e: "Owner · full access", r: "Admin" },
              { n: "Sana Riaz", e: "Sales lead · quotations", r: "Sales" },
              { n: "Omar Khalid", e: "Account manager · CRM", r: "Sales" },
              { n: "Hira Tariq", e: "QC & site inspections", r: "Operations" },
              { n: "Faisal Zeb", e: "Workshop floor", r: "Production" },
            ].map((m) => (
              <li key={m.n} className="flex items-center gap-3 py-2.5">
                <Avatar initials={m.n.split(" ").map((w) => w[0]).join("")} size="sm" />
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-small font-semibold text-ink">{m.n}</span>
                  <span className="block truncate text-caption text-muted">{m.e}</span>
                </span>
                <StatusBadge status={m.r === "Admin" ? "Approved" : m.r === "Sales" ? "Viewed" : "In Progress"} />
                <span className="hidden text-caption text-subtle sm:block">{m.r}</span>
              </li>
            ))}
          </ul>
        </Card>

        <Card>
          <CardHeader
            title="Theme — design tokens"
            description="Brand tokens drive the whole shell; overrides are tenant-configurable (§29)."
            action={<Badge tone="primary" dot={false}>Woodex v1.0</Badge>}
          />
          <ul className="grid grid-cols-3 gap-2.5">
            {swatches.map((s) => (
              <li key={s.name} className="overflow-hidden rounded-card border border-line">
                <div className="h-12" style={{ background: s.hex }} aria-hidden />
                <div className="px-2.5 py-2">
                  <p className="truncate text-caption font-semibold text-ink">{s.name}</p>
                  <p className="truncate font-mono text-[10px] text-subtle">{s.hex}</p>
                  <p className="truncate text-[10px] text-muted">{s.role}</p>
                </div>
              </li>
            ))}
          </ul>
          <p className="mt-3 text-caption text-subtle">Typography: Inter · 13px base · 8px spacing grid · 10px card radius.</p>
        </Card>
      </div>
    </div>
  );
}
