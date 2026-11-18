/*
 * What is typed is not lost without a question (master list T-043).
 *
 * A part of a screen whose fields hold work that waits to be saved is marked in the page:
 *
 *   <form wire:submit="save" data-unsaved>               saved by the form's own submit
 *   <form wire:submit="book" data-unsaved="saveDraft">   saved by its submit, and by these actions too
 *   <div data-unsaved="saveDraft">                       an editor without a form: saved by these actions
 *   <div data-unsaved="*">                               every field is stored when it is left: any answer saves it
 *   <form … data-unsaved data-unsaved-tab>               a tab of a screen: out of sight, its text is still held
 *   <form wire:submit="find" data-unsaved="off">         not work: a search, a question
 *   <input data-unsaved-skip>                            a field that is not work: a search, a tick that confirms
 *
 * <x-admin.modal submit="…"> marks itself; unsaved="…" on it names the actions that save it.
 * tests/Feature/UnsavedChangesTest.php reads every view and fails when a form, or a field of the admin, is
 * neither in a marked part nor on its written list of exceptions.
 *
 * How it goes:
 *  - Typing in a marked part, or changing a field of it, leaves a mark: this screen holds work that is not saved.
 *  - The mark goes when a save of that part succeeds. A save the server refused (a complaint under a field, a red
 *    or an amber notice) leaves it, and so does a save that was overtaken by more typing.
 *  - The mark goes when the part leaves the page: Cancel, Close and Escape are the person's own decision. A click
 *    beside a window is not one: it does not close a window whose form holds a mark (components/admin/modal). A
 *    tab keeps its mark while the screen still holds what was typed in it.
 *  - While a mark stands, leaving asks first. A link or a shortcut inside the admin, and the browser's Back button,
 *    get the admin's own dialog (resources/views/components/admin/unsaved-dialog.blade.php). Closing or reloading
 *    the tab gets the browser's own question.
 *  - Before anybody is asked, the field that holds the cursor is left (a field that is stored when it is left has
 *    then been sent) and a save on its way is waited for. What the save stored is not asked about.
 *
 * Not noticed: a row added, moved or removed by a button while nothing was typed.
 * Alpine comes from Livewire; nothing is imported here.
 */

const WORDS = 'What you entered on this screen is not saved yet. Leave without saving?';

/**
 * What a notice says, however the server wrote it: dispatch('toast', message: '…', type: 'error') arrives as
 * { message, type }; dispatch('toast', '…', 'error') arrives as ['…', 'error'], and used to be shown as a green
 * "Saved" whatever it said.
 */
export const toastOf = (detail) => {
    if (!Array.isArray(detail)) return { message: detail?.message, type: detail?.type };
    if (detail[0] !== null && typeof detail[0] === 'object') return { message: detail[0].message, type: detail[0].type };

    return { message: detail[0], type: detail[1] };
};

/** "component id|part" → what is not saved there. */
const marks = new Map();
/** Every edit gets a number, so that "typed again since" can be told. */
let edits = 0;
/** The question on the screen. */
let open = null;
/** A link was pressed and the answer is being worked out. */
let deciding = false;
/** History is being moved back to the screen that was kept. */
let returning = false;
/** The person chose to leave: nothing more is asked. */
let leaving = false;
/** Livewire's name for the page the person last typed on. */
let typedOn = null;
/** Where in the browser's history the last move started. */
let cameFrom = null;
/** How many steps the browser's history stands away from the page that is still on the screen. */
let away = 0;
/** Saves that were sent and whose answer is not yet worked into the page. */
let onTheWay = 0;
/** The watch itself is taking the cursor out of a field: that is no typing. */
let lettingGo = false;

const nameOf = (mark) => `${mark.id}|${mark.key}`;
const rootOf = (id) => document.querySelector(`[wire\\:id="${id}"]`);
const wireOf = (id) => window.Livewire?.find(id);
const pause = (milliseconds) => new Promise((done) => setTimeout(done, milliseconds));
const wired = (element, prefix) => [...element.attributes].find((attribute) => attribute.name.startsWith(prefix))?.value ?? null;

/** The screen's own copy of a field, as text that can be compared. Null when it cannot be read. */
const read = (wire, field) => {
    try {
        return JSON.stringify(wire.$get(field)) ?? 'undefined';
    } catch {
        return null;
    }
};

/** What a marked element says: its name among the parts of its screen, and what saves it. */
const describe = (part) => {
    const words = (part.dataset.unsaved ?? '').split(/\s+/).filter(Boolean);
    const submit = wired(part, 'wire:submit')?.match(/^\s*([\w$]+)/)?.[1] ?? null;

    return {
        key: submit ?? words.join(' '),
        any: words.includes('*'),
        saves: [...(submit ? [submit] : []), ...words.filter((word) => word !== '*')],
        tab: part.hasAttribute('data-unsaved-tab'),
    };
};

const partsOf = (root) => [root, ...root.querySelectorAll('[data-unsaved]')]
    .filter((part) => part.matches('[data-unsaved]') && part.dataset.unsaved !== 'off' && part.closest('[wire\\:id]') === root);

/** The part a mark belongs to, when it is on the page. Found by name: Livewire may redraw the element. */
const partOnScreen = (mark) => {
    const root = rootOf(mark.id);

    return (root && partsOf(root).find((part) => describe(part).key === mark.key)) ?? null;
};

/* ---------- Typing leaves a mark ---------- */
const note = (event) => {
    const field = event.target;
    // The browser reports a change when the watch takes the cursor out of a field (letGo). Nothing new was typed:
    // counting it would make a save that is on its way look overtaken.
    if (lettingGo) return;
    if (!(field instanceof Element) || field.closest('[data-unsaved-skip]')) return;
    const part = field.closest('[data-unsaved]');
    if (!part || part.dataset.unsaved === 'off') return;
    const root = part.closest('[wire\\:id]');
    // A field of another screen drawn inside this part (a picker, a search) is that screen's own business.
    if (!root || field.closest('[wire\\:id]') !== root) return;

    const said = describe(part);
    const id = root.getAttribute('wire:id');
    const mark = marks.get(`${id}|${said.key}`) ?? { id, ...said, fields: new Set(), waiting: 0, at: 0 };
    const model = wired(field, 'wire:model');
    if (model) mark.fields.add(model);
    mark.at = ++edits;
    marks.set(nameOf(mark), mark);
    // While a question is open the address may already be another page's (the Back button), and opening the
    // question takes the cursor out of the field, which the browser reports as a change of that field.
    if (open === null && !deciding && !returning) typedOn = window.history.state?.alpine?.documentId ?? null;
};

/** Does this element hold a part whose text is not saved? A window with such a form is not closed by a click beside it. */
const holds = (element) => standing() > 0 && [...marks.values()].some((mark) => element.contains(partOnScreen(mark)));

/** How many parts hold work that is not saved. A part that has left the page takes its mark with it. */
const standing = () => {
    for (const [name, mark] of marks) {
        if (!wireOf(mark.id) || !rootOf(mark.id)) marks.delete(name);
        else if (!mark.tab && !partOnScreen(mark)) marks.delete(name);
    }

    return marks.size;
};

/* ---------- An answer of the server takes the mark away, or leaves it ---------- */

/** The server refused something: a complaint under a field (wxInvalid), or a red or an amber notice. */
const complaint = (payload) => {
    const effects = payload?.effects ?? {};

    return effects.wxInvalid === true
        || (effects.dispatches ?? []).some((sent) => sent.name === 'toast' && ['error', 'warning'].includes(toastOf(sent.params).type));
};

/**
 * For a part whose fields are stored when they are left: was everything typed in this message? The field the
 * cursor is still in has not been sent, and then no answer can have saved it.
 */
const carried = (wire, part) => {
    const field = document.activeElement;
    if (!part || !field || !part.contains(field) || !('value' in field) || ['checkbox', 'radio', 'file'].includes(field.type)) return true;
    const model = wired(field, 'wire:model');
    if (!model) return true;
    try {
        return String(wire.$get(model) ?? '') === String(field.value);
    } catch {
        return true;
    }
};

const watchAnswers = () => {
    window.Livewire.interceptMessage(({ message, onSuccess, onFinish }) => {
        const id = message.component.id;
        const mine = [...marks.values()].filter((mark) => mark.id === id);
        if (mine.length === 0) return;

        const called = new Set(message.getActions().map((action) => action.name));
        const wire = wireOf(id);
        const sent = mine.map((mark) => ({
            mark,
            at: mark.at,
            saves: mark.any ? carried(wire, partOnScreen(mark)) : mark.saves.some((name) => called.has(name)),
            held: new Map([...mark.fields].map((field) => [field, read(wire, field)])),
        }));

        // A save on its way: a link pressed now waits for the answer before anybody is asked, and until the answer
        // is worked into the page. (The mark goes earlier, below; leaving between the two would swap the page
        // under an answer that is still being drawn into it.)
        let over = false;
        const saving = sent.filter((entry) => entry.saves);
        saving.forEach((entry) => { entry.mark.waiting += 1; });
        if (saving.length > 0) onTheWay += 1;
        onFinish(() => {
            if (over) return;
            over = true;
            saving.forEach((entry) => { entry.mark.waiting -= 1; });
            if (saving.length > 0) onTheWay -= 1;
            // The person asked to leave, and what held them has been saved meanwhile: the question answers itself,
            // once the answer is worked into the page.
            if (open && onTheWay === 0 && standing() === 0) open.answer(true);
        });

        onSuccess(({ payload, onMorphed }) => {
            // This runs before the answer is acted on, so a save that sends the person on to another screen has
            // taken its mark away before that move is looked at.
            const refused = complaint(payload);
            for (const entry of sent) {
                // Typed again while the answer was on its way: what is in the fields now was not in it.
                if (entry.mark.at === entry.at && entry.saves && !refused) marks.delete(nameOf(entry.mark));
            }

            onMorphed(() => {
                for (const { mark, at, held } of sent) {
                    // A tab that is out of sight: does the screen still hold anything that was typed in it?
                    if (!mark.tab || marks.get(nameOf(mark)) !== mark || mark.at !== at || partOnScreen(mark)) continue;
                    const now = wireOf(id);
                    const kept = now && [...held].some(([field, value]) => value !== null && read(now, field) === value);
                    if (!kept) marks.delete(nameOf(mark));
                }
            });
        });
    });
};

/* ---------- Asking ---------- */
const ask = () => new Promise((resolve) => {
    const box = document.querySelector('[data-unsaved-dialog]');
    if (!box || typeof box.showModal !== 'function') {
        resolve(window.confirm(WORDS));

        return;
    }

    const stay = box.querySelector('[data-unsaved-stay]');
    const leave = box.querySelector('[data-unsaved-leave]');
    const answer = (go) => {
        if (open === null) return;
        open = null;
        stay.removeEventListener('click', onStay);
        leave.removeEventListener('click', onLeave);
        box.removeEventListener('cancel', onCancel);
        window.removeEventListener('keydown', onKey, true);
        if (box.open) box.close();
        resolve(go);
    };
    const onStay = () => answer(false);
    const onLeave = () => answer(true);
    const onCancel = (event) => {
        event.preventDefault();
        answer(false);
    };
    // Escape means "Stay", and it must not reach the page behind, where Escape closes the very form that is being
    // kept. No other key reaches the page either: N, Q and G are shortcuts that open another screen. Tab, Enter and
    // Space still do their work on the two buttons, which is the browser's own doing.
    const onKey = (event) => {
        event.stopImmediatePropagation();
        if (event.key === 'Escape') {
            event.preventDefault();
            answer(false);
        }
    };

    open = { answer };
    stay.addEventListener('click', onStay);
    leave.addEventListener('click', onLeave);
    box.addEventListener('cancel', onCancel);
    window.addEventListener('keydown', onKey, true);
    box.showModal();
    stay.focus();
});

/**
 * The field that holds the cursor gives up what was typed in it, as it does when the person clicks elsewhere.
 *
 * Livewire stops the browser's own handling of a press on a link, and the cursor then stays where it was; the Back
 * button leaves it there too. A field that is stored when it is left has then not been left: nothing was sent, and
 * the question came up over a figure that only needed a moment to be saved (it was sent when the question took the
 * cursor, and the question then closed by itself: a window that flashed).
 */
const letGo = () => {
    const field = document.activeElement;
    if (!(field instanceof HTMLElement) || !field.matches('input, textarea, select')) return null;
    const part = field.closest('[data-unsaved]');
    if (!part || part.dataset.unsaved === 'off') return null;

    lettingGo = true;
    try {
        field.blur();
    } finally {
        lettingGo = false;
    }

    return field;
};

/**
 * Waits while `busy` says so, for at most `limit` milliseconds of the page's own time: a stretch in which the page
 * itself stood still (a tab that slept, a machine that was busy) is not time in which an answer could have been
 * worked in, and is not counted as waiting for one.
 */
const waitWhile = async (busy, limit) => {
    let waited = 0;
    while (waited < limit && busy()) {
        const from = Date.now();
        await pause(40);
        waited += Math.min(Date.now() - from, 80);
    }
};

/** True when the person may go: nothing stands, a save on its way took the mark away, or they said so. */
const mayLeave = async () => {
    const field = letGo();
    // Leaving the field gets a moment to become a message, and a save on its way gets the time to come back and
    // to be worked into the page, before anybody is asked anything.
    await waitWhile(() => true, 120);
    await waitWhile(() => onTheWay > 0, 5000);

    const go = standing() === 0 || await ask();
    // "Stay": the cursor goes back to where the person was.
    if (!go && field?.isConnected) field.focus({ preventScroll: true });

    return go;
};

const onNavigate = (event) => {
    if (returning) {
        // History is moving back to the screen that was kept. It is still on the page: nothing is swapped.
        returning = false;
        event.preventDefault();

        return;
    }
    if (leaving || standing() === 0) return;

    const { url, history } = event.detail;
    if (history) {
        const here = window.navigation?.currentEntry?.index ?? null;
        // A move inside the same page (a filter in the address) takes nothing away.
        if (away === 0 && typedOn !== null && window.history.state?.alpine?.documentId === typedOn) return;
        // Back has already changed the address. Without the browser's record of where the move started it cannot
        // be put back, so a browser that keeps no such record is let through, as before.
        if (here === null || cameFrom === null || cameFrom < 0 || cameFrom === here) return;
        away += cameFrom - here;
    }

    event.preventDefault();
    // A second press while the question is open changes nothing but how far the address has moved.
    if (open || deciding) return;
    deciding = true;

    mayLeave().then((go) => {
        deciding = false;
        const moved = away;
        away = 0;
        if (moved !== 0 && !go) {
            returning = true;
            window.history.go(moved);
        } else if (moved !== 0) {
            // The address is already the other page's. Loading it shows that page.
            leaving = true;
            marks.clear();
            window.location.reload();
        } else if (go) {
            leaving = true;
            marks.clear();
            window.Livewire.navigate(url.href ?? String(url));
        }
    });
};

const onUnload = (event) => {
    if (leaving || standing() === 0) return;
    // The browser shows its own words; a page cannot choose them.
    event.preventDefault();
    event.returnValue = '';
};

export function registerUnsavedChanges() {
    document.addEventListener('input', note, true);
    document.addEventListener('change', note, true);
    document.addEventListener('livewire:init', watchAnswers);
    document.addEventListener('livewire:navigate', onNavigate);
    document.addEventListener('livewire:navigated', () => { leaving = false; });
    window.addEventListener('beforeunload', onUnload);
    window.navigation?.addEventListener('currententrychange', (event) => { cameFrom = event.from?.index ?? null; });
    // A move back that reached an entry Livewire does not handle must not leave "returning" standing.
    window.addEventListener('popstate', () => setTimeout(() => { returning = false; }));

    // For the page (the modal asks "holds") and for the journeys in the browser (scripts/os-journeys.mjs).
    window.wxUnsaved = {
        standing,
        holds,
        parts: () => [...marks.values()].map((mark) => ({ part: mark.key, fields: [...mark.fields], saving: mark.waiting > 0 })),
        // What the watch believes at this moment: for the note of a journey step that failed.
        state: () => ({ asking: open !== null, deciding, returning, leaving, away, onTheWay, typedOn, cameFrom, here: window.navigation?.currentEntry?.index ?? null }),
    };
}
