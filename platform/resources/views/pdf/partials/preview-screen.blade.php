@if (! empty($preview))
    {{-- Screen-only sheet presentation for the builders' live preview panes. The preview flag is only
         set by QuotePdf::html() and InvoicePdf::html(), so printed PDFs never receive these rules. --}}
    <style>
        html { background: #e9e7e2; }
        body { background: #ffffff; margin: 18px auto; max-width: 794px; padding: 34px 42px 42px; box-shadow: 0 1px 10px rgba(12, 22, 40, .25); }
        header { position: static; height: auto; margin-bottom: 12px; }
        footer { position: static; height: auto; margin-top: 24px; }
    </style>
@endif
