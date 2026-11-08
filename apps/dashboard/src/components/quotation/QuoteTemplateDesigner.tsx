import { useEffect, useRef, useState, type DragEvent } from "react";
import { Button } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useAuth } from "@/lib/auth";
import { mutate, useApi, type ApiQuoteTemplate, type ApiQuoteTemplateBlock, type ApiQuoteTemplateBlockType } from "@/lib/api";

export const PAYMENT_TERMS_NOTE = "Final payment terms will be included in formal quotation.";
type Region = "header" | "footer";

const block = (id: string, type: ApiQuoteTemplateBlockType, label: string, content = ""): ApiQuoteTemplateBlock => ({
  id, type, label, content, visible: true,
});

export const DEFAULT_QUOTE_TEMPLATE: ApiQuoteTemplate = {
  id: "builtin-woodex-classic",
  name: "Woodex classic",
  brandColor: "#318b45",
  logoText: "W",
  companyName: "Woodex Furniture",
  companyAddress: "",
  companyPhone: "",
  companyEmail: "",
  header: [
    block("brand", "brand", "Logo / company identity"),
    block("title", "title", "Quotation"),
    block("meta", "meta", "Date and reference"),
    block("customer", "customer", "Customer details"),
  ],
  footer: [
    block("terms", "terms", "Quote terms"),
    block("payment-note", "payment-note", "Formal payment note", PAYMENT_TERMS_NOTE),
    block("contact", "contact", "Company contact"),
    block("closing", "custom", "Thank-you note", "Thank you for your business."),
  ],
};

const cloneTemplate = (template: ApiQuoteTemplate): ApiQuoteTemplate => ({
  ...template,
  header: template.header.map((item) => ({ ...item })),
  footer: template.footer.map((item) => ({ ...item })),
});

const newBlockId = () => `custom-${globalThis.crypto?.randomUUID?.() ?? Math.random().toString(36).slice(2)}`;

export function QuoteTemplateDesigner({
  value,
  onChange,
  disabled = false,
}: {
  value: ApiQuoteTemplate;
  onChange: (template: ApiQuoteTemplate) => void;
  disabled?: boolean;
}) {
  const { push } = useToast();
  const { caps } = useAuth();
  const canManageTemplates = caps.includes("*") || caps.includes("quote.manage");
  const library = useApi<{ total: number; items: ApiQuoteTemplate[] }>("/api/quote-templates");
  const templates = library.data?.items ?? [];
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<ApiQuoteTemplate>(() => cloneTemplate(value));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState("");
  const [dragging, setDragging] = useState<{ region: Region; id: string } | null>(null);

  useEffect(() => {
    if (library.error) setError("Saved templates could not be loaded. Check your connection and retry.");
  }, [library.error]);

  const chooseTemplate = (id: string) => {
    const selected = id === DEFAULT_QUOTE_TEMPLATE.id
      ? DEFAULT_QUOTE_TEMPLATE
      : templates.find((template) => template.id === id) ?? value;
    onChange(cloneTemplate(selected));
  };

  const openDesigner = () => {
    setDraft(cloneTemplate(value));
    setError("");
    setOpen(true);
  };

  const updateTemplate = (patch: Partial<ApiQuoteTemplate>) =>
    setDraft((current) => ({ ...current, ...patch }));

  const updateBlock = (region: Region, id: string, patch: Partial<ApiQuoteTemplateBlock>) => {
    setDraft((current) => ({
      ...current,
      [region]: current[region].map((item) => item.id === id
        ? { ...item, ...patch, ...(item.type === "payment-note" ? { visible: true, content: PAYMENT_TERMS_NOTE } : {}) }
        : item),
    }));
  };

  const moveBlock = (region: Region, fromId: string, toId: string) => {
    if (fromId === toId) return;
    setDraft((current) => {
      const items = [...current[region]];
      const fromIndex = items.findIndex((item) => item.id === fromId);
      const toIndex = items.findIndex((item) => item.id === toId);
      if (fromIndex < 0 || toIndex < 0) return current;
      const [moved] = items.splice(fromIndex, 1);
      items.splice(toIndex, 0, moved);
      return { ...current, [region]: items };
    });
  };

  const shiftBlock = (region: Region, id: string, delta: -1 | 1) => {
    const items = draft[region];
    const index = items.findIndex((item) => item.id === id);
    const next = index + delta;
    if (index < 0 || next < 0 || next >= items.length) return;
    moveBlock(region, id, items[next].id);
  };

  const dragRegionRef = useRef<Region | null>(null);
  /* Chromium starts a text selection instead of an HTML5 drag when the press
     lands on an <input>, so the grab affordance is its own drag source. The row
     stays draggable for presses on its padding. */
  const startDrag = (event: DragEvent, region: Region, id: string) => {
    event.dataTransfer.effectAllowed = "move";
    event.dataTransfer.setData("text/plain", id);
    dragRegionRef.current = region;
    setDragging({ region, id });
  };

  const addCustomBlock = (region: Region) => {
    const item = block(newBlockId(), "custom", "Custom text", "Add your text here.");
    setDraft((current) => ({ ...current, [region]: [...current[region], item] }));
  };

  const removeCustomBlock = (region: Region, id: string) => {
    setDraft((current) => ({ ...current, [region]: current[region].filter((item) => item.id !== id) }));
  };

  const saveTemplate = async (asNew = false) => {
    if (!asNew && library.loading) {
      setError("Wait for the saved-template library to finish loading before updating a template.");
      return;
    }
    if (!asNew && library.error) {
      setError("The saved-template library is unavailable. Retry loading it or use Save as new.");
      return;
    }
    if (!draft.name.trim()) {
      setError("Enter a name for this template.");
      return;
    }
    setSaving(true);
    setError("");
    try {
      const existing = !asNew && templates.some((item) => item.id === draft.id);
      const payload = { ...draft, ...(asNew ? { id: undefined } : {}) };
      const saved = existing
        ? await mutate<ApiQuoteTemplate>(`/api/quote-templates/${encodeURIComponent(draft.id)}`, payload)
        : await mutate<ApiQuoteTemplate>("/api/quote-templates", payload, "POST");
      library.setData((current) => {
        const currentItems = current?.items ?? [];
        const items = currentItems.some((item) => item.id === saved.id)
          ? currentItems.map((item) => item.id === saved.id ? saved : item)
          : [saved, ...currentItems];
        return { total: items.length, items };
      });
      setDraft(cloneTemplate(saved));
      if (!disabled) onChange(cloneTemplate(saved));
      push({ tone: "success", title: existing ? "Quotation template updated" : "Quotation template saved", desc: saved.name });
    } catch (saveError) {
      setError(saveError instanceof Error ? saveError.message : "Could not save the template.");
    } finally {
      setSaving(false);
    }
  };

  const deleteTemplate = async () => {
    if (!templates.some((item) => item.id === draft.id)) {
      setError("Built-in and unsaved templates cannot be deleted.");
      return;
    }
    setSaving(true);
    setError("");
    try {
      await mutate(`/api/quote-templates/${encodeURIComponent(draft.id)}`, undefined, "DELETE");
      library.setData((current) => {
        const items = (current?.items ?? []).filter((item) => item.id !== draft.id);
        return { total: items.length, items };
      });
      setDraft(cloneTemplate(DEFAULT_QUOTE_TEMPLATE));
      if (!disabled) onChange(cloneTemplate(DEFAULT_QUOTE_TEMPLATE));
      push({ tone: "success", title: "Quotation template deleted" });
    } catch (deleteError) {
      setError(deleteError instanceof Error ? deleteError.message : "Could not delete the template.");
    } finally {
      setSaving(false);
    }
  };

  const applyDraft = () => {
    onChange(cloneTemplate(draft));
    setOpen(false);
  };

  const renderBlockList = (region: Region, title: string, items: ApiQuoteTemplateBlock[]) => (
    <section className="rounded-card border border-line bg-slate-50/60 p-3 sm:p-4">
      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
        <div>
          <h4 className="text-small font-semibold text-ink">{title}</h4>
          <p className="text-caption text-muted">Drag to reorder; use the arrows for keyboard-friendly movement.</p>
        </div>
        <Button variant="secondary" size="sm" onClick={() => addCustomBlock(region)} disabled={saving || items.length >= 30}>+ Add text block</Button>
      </div>
      <div className="space-y-2">
        {items.map((item, index) => (
          <div
            key={item.id}
            data-block-row=""
            draggable={!saving}
            onDragStart={(event) => startDrag(event, region, item.id)}
            onDragEnd={() => { dragRegionRef.current = null; setDragging(null); }}
            onDragOver={(event) => event.preventDefault()}
            onDrop={(event) => {
              event.preventDefault();
              /* Read the source from the payload first: relying on React state alone
                 made the drop a no-op whenever the dragstart re-render had not
                 committed yet (as seen in headless Chromium). */
              const fromId = event.dataTransfer.getData("text/plain") || dragging?.id;
              const fromRegion = dragging?.region ?? dragRegionRef.current;
              if (fromId && fromRegion === region) moveBlock(region, fromId, item.id);
              setDragging(null);
            }}
            className={`rounded-control border bg-white p-3 transition-colors ${dragging?.id === item.id ? "border-primary-500 opacity-60" : "border-line"}`}
          >
            <div className="flex items-start gap-2">
              <span
                role="button"
                tabIndex={-1}
                aria-label={`Drag ${item.label}`}
                data-block-drag-handle=""
                draggable={!saving}
                onDragStart={(event) => startDrag(event, region, item.id)}
                title="Drag to reorder"
                className="cursor-grab select-none pt-1 text-lg leading-none text-slate-400 active:cursor-grabbing"
              >⠿</span>
              <div className="grid min-w-0 flex-1 grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_auto]">
                <label className="block text-caption font-medium text-slate-700">
                  Block label
                  <Input className="mt-1" value={item.label} disabled={saving} onChange={(event) => updateBlock(region, item.id, { label: event.target.value })} />
                </label>
                <div className="flex items-end gap-1">
                  <Button type="button" variant="ghost" size="sm" aria-label={`Move ${item.label} up`} title="Move up" disabled={saving || index === 0} onClick={() => shiftBlock(region, item.id, -1)}>↑</Button>
                  <Button type="button" variant="ghost" size="sm" aria-label={`Move ${item.label} down`} title="Move down" disabled={saving || index === items.length - 1} onClick={() => shiftBlock(region, item.id, 1)}>↓</Button>
                  {item.type === "custom" && <Button type="button" variant="ghost" size="sm" aria-label={`Remove ${item.label}`} disabled={saving} onClick={() => removeCustomBlock(region, item.id)}>Remove</Button>}
                </div>
                {item.type === "custom" && (
                  <label className="block text-caption font-medium text-slate-700 sm:col-span-2">
                    Text content
                    <Textarea className="mt-1" rows={2} value={item.content ?? ""} disabled={saving} onChange={(event) => updateBlock(region, item.id, { content: event.target.value })} />
                  </label>
                )}
                <label className="flex items-center gap-2 text-caption text-slate-600 sm:col-span-2">
                  <input
                    type="checkbox"
                    checked={item.type === "payment-note" || item.visible}
                    disabled={saving || item.type === "payment-note"}
                    onChange={(event) => updateBlock(region, item.id, { visible: event.target.checked })}
                    className="h-4 w-4 accent-primary-600"
                  />
                  {item.type === "payment-note" ? "Required formal-payment wording (cannot be removed)" : "Show this block on the quotation"}
                </label>
              </div>
            </div>
          </div>
        ))}
      </div>
    </section>
  );

  return (
    <>
      <Card>
        <CardHeader
          title="Quotation template"
          description="Choose a reusable header/footer layout. The selected snapshot is stored with the quote and used for print/PDF."
          action={canManageTemplates ? (
            <div className="flex gap-2">
              {library.error && <Button variant="ghost" size="sm" onClick={() => void library.reload()}>Retry library</Button>}
              <Button variant="secondary" size="sm" onClick={openDesigner}>Design template</Button>
            </div>
          ) : undefined}
        />
        <div className="grid gap-3 sm:grid-cols-[minmax(0,1fr)_auto] sm:items-end">
          <Field label="Template used for this quotation">
            <Select value={value.id} disabled={disabled || !canManageTemplates} onChange={(event) => chooseTemplate(event.target.value)}>
              <option value={DEFAULT_QUOTE_TEMPLATE.id}>{DEFAULT_QUOTE_TEMPLATE.name} · built in</option>
              {value.id !== DEFAULT_QUOTE_TEMPLATE.id && !templates.some((item) => item.id === value.id) && (
                <option value={value.id}>{value.name} · quote snapshot</option>
              )}
              {templates.map((template) => <option key={template.id} value={template.id}>{template.name}</option>)}
            </Select>
          </Field>
          <p className="pb-2 text-caption text-muted">{library.loading ? "Loading saved templates…" : `${templates.length} reusable template${templates.length === 1 ? "" : "s"} saved`}</p>
        </div>
        {library.error && <p className="mt-2 text-caption text-danger-strong">Template library unavailable: {library.error}</p>}
      </Card>

      <Modal
        open={open}
        onClose={() => setOpen(false)}
        title="Quotation template designer"
        description="Customize the printable quotation header and footer. Drag to reorder blocks; the middle line-item table and totals stay consistent."
        className="max-w-6xl max-h-[92vh] overflow-y-auto"
        footer={(
          <div className="flex w-full flex-wrap items-center justify-between gap-2">
            <div className="flex flex-wrap gap-2">
              {templates.some((item) => item.id === draft.id) && <Button variant="danger" size="sm" disabled={saving} onClick={deleteTemplate}>Delete template</Button>}
              <Button variant="secondary" size="sm" disabled={saving} onClick={() => void saveTemplate(true)}>Save as new</Button>
              <Button variant="secondary" size="sm" disabled={saving} onClick={() => void saveTemplate(false)}>Save template</Button>
            </div>
            <div className="flex gap-2">
              <Button variant="secondary" disabled={saving} onClick={() => setOpen(false)}>Cancel</Button>
              <Button disabled={saving || disabled} onClick={applyDraft}>Use for this quote</Button>
            </div>
          </div>
        )}
      >
        <div className="space-y-4">
          <div className="grid gap-3 rounded-card border border-line bg-white p-3 sm:grid-cols-2 lg:grid-cols-3">
            <Field label="Template name" required>
              <Input value={draft.name} disabled={saving} onChange={(event) => updateTemplate({ name: event.target.value })} />
            </Field>
            <Field label="Accent color" hint="Used for the quotation table heading and brand mark">
              <Input type="color" value={draft.brandColor} disabled={saving} className="p-1" onChange={(event) => updateTemplate({ brandColor: event.target.value })} />
            </Field>
            <Field label="Logo mark" hint="Enter up to three initials or letters">
              <Input maxLength={3} value={draft.logoText ?? draft.companyName.trim().charAt(0)} disabled={saving} onChange={(event) => updateTemplate({ logoText: event.target.value.slice(0, 3).toUpperCase() })} />
            </Field>
            <Field label="Company name" required>
              <Input value={draft.companyName} disabled={saving} onChange={(event) => updateTemplate({ companyName: event.target.value })} />
            </Field>
            <Field label="Company address">
              <Input value={draft.companyAddress} disabled={saving} placeholder="Add approved business address" onChange={(event) => updateTemplate({ companyAddress: event.target.value })} />
            </Field>
            <Field label="Phone">
              <Input value={draft.companyPhone} disabled={saving} placeholder="Add approved phone number" onChange={(event) => updateTemplate({ companyPhone: event.target.value })} />
            </Field>
            <Field label="Email">
              <Input type="email" value={draft.companyEmail} disabled={saving} placeholder="Add approved business email" onChange={(event) => updateTemplate({ companyEmail: event.target.value })} />
            </Field>
            <div className="flex items-center gap-3 rounded-control border border-line bg-slate-50/70 p-3 sm:col-span-2 lg:col-span-3">
              <span className="grid h-11 w-11 shrink-0 place-items-center rounded-lg text-lg font-bold text-white" style={{ backgroundColor: draft.brandColor }}>
                {(draft.logoText?.trim() || draft.companyName.trim().charAt(0) || "W").toUpperCase()}
              </span>
              <span className="min-w-0"><span className="block text-caption font-semibold tracking-wide text-muted uppercase">Logo preview</span><span className="block truncate text-small font-semibold text-ink">{draft.companyName || "Company name"}</span></span>
            </div>
          </div>
          {renderBlockList("header", "Header blocks", draft.header)}
          {renderBlockList("footer", "Footer blocks", draft.footer)}
          {error && <p role="alert" className="rounded-control border border-danger/30 bg-danger/5 px-3 py-2 text-small text-danger-strong">{error}</p>}
          <aside className="rounded-control border border-primary-200 bg-primary-50/60 p-3 text-caption text-slate-700">
            Required wording remains: <strong>{PAYMENT_TERMS_NOTE}</strong> The sample's 75%/25% advance split is not included.
          </aside>
        </div>
      </Modal>
    </>
  );
}
