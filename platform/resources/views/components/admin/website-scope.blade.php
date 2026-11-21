{{-- What the Website screens manage, said on each of them (DL-046, docs/woodex-os/12-DECISION-LOG.md).

     They manage pages that this admin serves itself. Woodex's shop is the React application, and nothing on these
     screens changes it (docs/woodex-os/25-INTERIOR-ADMIN-REUSE-AND-UPGRADE-PLAN.md section 5; list lines T-115 and
     T-116). A person who is shown twelve screens with empty lists is owed that sentence on each of them. --}}
<aside data-website-scope role="note" {{ $attributes->merge(['class' => 'mb-5 flex items-start gap-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-theme-sm text-gray-600 dark:border-gray-800 dark:bg-white/[0.03] dark:text-gray-300']) }}>
    <x-icon name="layout" class="mt-0.5 size-5 shrink-0 text-gray-400" />
    <p><strong class="font-semibold text-gray-800 dark:text-white/90">These screens are for pages this admin serves itself.</strong> Woodex's shop is a separate application, and nothing here changes it.</p>
</aside>
