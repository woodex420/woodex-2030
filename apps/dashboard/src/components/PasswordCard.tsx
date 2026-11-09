import { useState } from "react";
import { Card, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Input } from "@/components/ui/Field";
import { useToast } from "@/components/ui/Toast";
import { mutate } from "@/lib/api";
import { Lock, ShieldCheck } from "@/icons";

/* Changing your own password.
 *
 * This is deliberately not part of team administration. A viewer, a technician
 * on the floor, anybody with a login can come here — before this existed, the
 * only people who could change a password were the ones holding
 * `users.manage`, which meant everybody else was stuck with whatever they were
 * handed on their first day.
 *
 * The form asks for the current password because a session is not an identity:
 * a borrowed laptop should not be an account takeover with extra steps.
 */
export function PasswordCard() {
  const { push } = useToast();
  const [current, setCurrent] = useState("");
  const [next, setNext] = useState("");
  const [again, setAgain] = useState("");
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const submit = async () => {
    if (busy) return;
    setErr(null);
    if (!next || next.length < 6) { setErr("A new password needs at least 6 characters."); return; }
    if (next !== again) { setErr("The two new passwords do not match."); return; }
    if (next === current) { setErr("That is already your password — pick a different one."); return; }
    setBusy(true);
    try {
      const out = await mutate<{ sessionsEnded: number }>("/api/auth/change-password", {
        currentPassword: current, newPassword: next,
      }, "POST");
      setCurrent(""); setNext(""); setAgain("");
      push({ tone: "success", title: "Password changed",
        desc: out.sessionsEnded ? `${out.sessionsEnded} other session${out.sessionsEnded === 1 ? "" : "s"} signed out` : undefined });
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not change the password.");
    } finally { setBusy(false); }
  };

  return (
    <Card>
      <CardHeader title="Your password"
        description="Change it whenever you like — you do not need an owner to do this for you." />
      <div className="p-4 pt-3 space-y-3 max-w-md">
        <label className="block">
          <span className="mb-1 block text-caption font-semibold text-ink">Current password</span>
          <Input type="password" autoComplete="current-password" value={current} onChange={(e) => setCurrent(e.target.value)} />
        </label>
        <label className="block">
          <span className="mb-1 block text-caption font-semibold text-ink">New password</span>
          <Input type="password" autoComplete="new-password" value={next} onChange={(e) => setNext(e.target.value)} />
        </label>
        <label className="block">
          <span className="mb-1 block text-caption font-semibold text-ink">New password again</span>
          <Input type="password" autoComplete="new-password" value={again} onChange={(e) => setAgain(e.target.value)} />
        </label>
        {err && <p className="text-caption text-danger-strong">{err}</p>}
        <div className="flex items-center gap-2">
          <Button onClick={() => void submit()} disabled={busy || !current || !next}>
            <Lock size={13} /> {busy ? "Changing…" : "Change password"}
          </Button>
        </div>
        <p className="text-[11px] text-subtle flex items-start gap-1.5">
          <ShieldCheck size={13} className="mt-0.5 shrink-0" />
          Changing your password signs out every other session — if somebody else had your old password, this is the moment it stops working.
          The window you are using now stays signed in.
        </p>
      </div>
    </Card>
  );
}
