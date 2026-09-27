{{-- Workbench sign-in: an onyx half with the brand and the greeting beside the
     form (from 900 px width, see .wb-login-aside in werkbank.css). Decoration
     only; the form keeps its own heading, the version sits in the page footer. --}}
<div class="wb-login-aside" aria-hidden="true">
    @include('filament.brand-logo')
    <div class="wb-login-aside__greeting">
        <p class="wb-login-aside__hello">Moin.</p>
        <p class="wb-login-aside__area">Zahlungen, Tickets und Abrechnung</p>
    </div>
</div>
