<?php
/**
 * Fabonic Real Estate — Lease Template Seed Data
 * ------------------------------------------------------------
 * Canonical source for the built-in lease templates that every tenant
 * starts with. Used by:
 *   - api/seed-lease-templates.php  (seeds existing tenants; also emits SQL)
 *   - sql/tenant_schema.sql         (INSERT block generated via --emit-sql)
 *
 * Content is an HTML *fragment* (no <html>/<body>) — modules/leases/print.php
 * substitutes {{placeholders}} and drops it into its own print wrapper.
 *
 * Supported placeholders (see modules/leases/print.php):
 *   {{tenant_name}} {{tenant_phone}} {{tenant_email}} {{tenant_id_type}}
 *   {{tenant_id_number}} {{unit_number}} {{unit_type}} {{property_name}}
 *   {{property_address}} {{property_city}} {{rent_amount}} {{deposit_amount}}
 *   {{payment_frequency}} {{payment_due_day}} {{start_date}} {{end_date}}
 *   {{terms}} {{company_name}} {{company_phone}} {{company_email}}
 *   {{company_address}} {{date_today}} {{lease_id}}
 *
 * NOTE: These are professional starting drafts, not legal advice. Tenancy law
 * varies by jurisdiction (notice periods, deposit handling, statutory rights).
 * Each landlord should have their advocate review and adjust before use — every
 * template is fully editable at modules/leases/templates.php.
 */

/** Brand green used by print.php's built-in document. */
const LT_ACCENT = '#1a6b4a';

/**
 * Centred title block, matching print.php's built-in default document.
 */
function ltDocHeader(string $title): string
{
    return '<div style="text-align:center;margin-bottom:22px;">
    <h1 style="font-size:24px;margin-bottom:5px;">{{company_name}}</h1>
    <h2 style="font-size:18px;color:#555;margin-top:0;">' . $title . '</h2>
    <hr style="border:2px solid ' . LT_ACCENT . ';">
</div>
<p style="font-size:12px;color:#777;">Reference: LSE-{{lease_id}} &nbsp;&nbsp;|&nbsp;&nbsp; Prepared: {{date_today}}</p>';
}

/**
 * Two-column signature block.
 */
function ltSignatures(string $leftRole = 'Landlord', string $rightRole = 'Tenant'): string
{
    return '<div style="margin-top:45px;">
    <div style="display:inline-block;width:45%;vertical-align:top;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>' . $leftRole . ' Signature</strong><br>
            {{company_name}}<br>
            Date: ____________________
        </p>
    </div>
    <div style="display:inline-block;width:45%;vertical-align:top;float:right;">
        <p style="border-top:1px solid #000;padding-top:5px;margin-top:60px;">
            <strong>' . $rightRole . ' Signature</strong><br>
            {{tenant_name}}<br>
            Date: ____________________
        </p>
    </div>
</div>
<div style="clear:both;"></div>
<p style="margin-top:30px;font-size:12px;color:#777;">Witness (optional): ______________________________ &nbsp;&nbsp; Signature: __________________ &nbsp;&nbsp; Date: ____________</p>';
}

/**
 * Assemble a document from a numbered list of [heading, html] sections.
 */
function ltBuild(string $title, array $sections, string $leftRole = 'Landlord', string $rightRole = 'Tenant'): string
{
    $html = ltDocHeader($title);
    $n = 0;
    foreach ($sections as [$heading, $body]) {
        $n++;
        $html .= "\n<h3 style=\"font-size:16px;color:" . LT_ACCENT . ";border-bottom:1px solid #ccc;padding-bottom:5px;\">"
            . $n . '. ' . strtoupper($heading) . "</h3>\n" . trim($body) . "\n";
    }
    return $html . "\n" . ltSignatures($leftRole, $rightRole) . "\n";
}

/** Shared clause fragments reused across several templates. */
function ltPartiesBlock(string $landlordRole = 'Landlord', string $tenantRole = 'Tenant'): string
{
    return '<p><strong>' . $landlordRole . ':</strong> {{company_name}}<br>
Address: {{company_address}}<br>
Phone: {{company_phone}} &nbsp;|&nbsp; Email: {{company_email}}</p>
<p><strong>' . $tenantRole . ':</strong> {{tenant_name}}<br>
Phone: {{tenant_phone}} &nbsp;|&nbsp; Email: {{tenant_email}}<br>
Identification: {{tenant_id_type}} — {{tenant_id_number}}</p>
<p>The parties agree as set out below. In this Agreement, "the Premises" means the property described in the next clause.</p>';
}

function ltPremisesBlock(): string
{
    return '<p><strong>Property:</strong> {{property_name}}<br>
<strong>Unit:</strong> {{unit_number}} ({{unit_type}})<br>
<strong>Address:</strong> {{property_address}}, {{property_city}}</p>';
}

function ltTermBlock(): string
{
    return '<p>This Agreement runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong> ("the Term").</p>
<p>Continued occupation after the end of the Term, with the Landlord accepting rent, continues the tenancy on the same terms on a month-to-month basis until either party gives written notice under the Termination clause.</p>';
}

function ltRentBlock(): string
{
    return '<p>Rent is <strong>{{rent_amount}}</strong>, payable <strong>{{payment_frequency}}</strong> in advance, on or before day <strong>{{payment_due_day}}</strong> of each payment period.</p>
<p>Rent is paid to the Landlord by the method the Landlord notifies in writing. The Tenant is responsible for any bank or transfer charges. A receipt is issued for every payment.</p>
<p>Rent not received within seven (7) days of its due date may attract a late fee at the rate the Landlord has notified in writing. Accepting a late payment does not waive the Landlord\'s rights.</p>';
}

function ltDepositBlock(): string
{
    return '<p>The Tenant pays a security deposit of <strong>{{deposit_amount}}</strong> before taking possession.</p>
<p>The deposit is security against unpaid rent, unpaid utilities, and damage beyond fair wear and tear. It is <em>not</em> rent and may not be used by the Tenant as the final period\'s rent.</p>
<p>Within thirty (30) days after the Tenant vacates and returns all keys, the Landlord refunds the deposit less any lawful deductions, together with a written statement itemising each deduction.</p>';
}

function ltTermsBlock(): string
{
    return '<p>{{terms}}</p>';
}

function ltGoverningBlock(): string
{
    return '<p>This Agreement is governed by the laws in force where the Premises are situated.</p>
<p>The parties will first attempt to resolve any dispute by good-faith discussion. Failing that, the dispute may be referred to the competent tribunal or court having jurisdiction over the Premises.</p>';
}

function ltEntireBlock(): string
{
    return '<p>This document is the entire agreement between the parties and replaces any earlier understanding, whether spoken or written. Any change must be in writing and signed by both parties.</p>
<p>If any clause is found unenforceable, the remaining clauses stay in full force.</p>
<p>Each party confirms they have read and understood this Agreement, and have signed it freely.</p>';
}

/**
 * The built-in templates.
 *
 * @return array<int, array{name:string, content:string, is_default:int}>
 */
function leaseTemplateSeedData(): array
{
    $templates = [];

    // ------------------------------------------------------------------
    // 1. Standard Residential Lease Agreement  (the default)
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Standard Residential Lease Agreement',
        'is_default' => 1,
        'content' => ltBuild('RESIDENTIAL LEASE AGREEMENT', [
            ['Parties', ltPartiesBlock()],
            ['The Premises', ltPremisesBlock() . '
<p>The Premises are let for residential use only, together with the fixtures, fittings and appliances listed in the inventory signed at handover.</p>'],
            ['Term', ltTermBlock()],
            ['Rent', ltRentBlock()],
            ['Security Deposit', ltDepositBlock()],
            ['Utilities and Outgoings', '<p>Unless stated otherwise in the Additional Terms, the Tenant pays for electricity, water, gas, refuse collection, internet and telephone consumed at the Premises, and keeps all such accounts current.</p>
<p>The Landlord pays land rates, statutory property taxes and building insurance.</p>
<p>The Tenant produces evidence that all utility accounts are settled before vacating.</p>'],
            ['Use of the Premises', '<p>The Premises are used as a private residence by the Tenant and the occupants declared to the Landlord in writing. Any change in the number of occupants requires the Landlord\'s prior written consent.</p>
<p>The Tenant will not carry on any trade or business at the Premises, will not do anything that is unlawful, a nuisance, or that invalidates the building insurance, and will observe any house rules notified in writing.</p>
<p>Pets are not kept at the Premises without the Landlord\'s prior written consent.</p>'],
            ['Maintenance and Repairs', '<p><strong>The Landlord</strong> keeps the structure, roof, exterior walls, and the main water, electrical, drainage and sanitary installations in good repair, and attends to reported faults within a reasonable time.</p>
<p><strong>The Tenant</strong> keeps the interior clean and in good order, replaces consumables such as light bulbs, and promptly reports any defect, leak or breakage in writing.</p>
<p>The Tenant is responsible for the cost of repairing damage caused by the Tenant, the Tenant\'s household, or the Tenant\'s visitors.</p>'],
            ['Alterations', '<p>The Tenant makes no structural alteration, no change to locks, and no permanent fixture or installation without the Landlord\'s prior written consent.</p>
<p>Approved alterations become part of the Premises at the end of the Term unless the parties agree otherwise in writing.</p>'],
            ['Landlord\'s Right of Entry', '<p>The Landlord, or a person authorised by the Landlord, may enter the Premises to inspect, to carry out repairs, or to show the Premises to a prospective tenant or buyer, after giving the Tenant at least twenty-four (24) hours\' notice and at a reasonable hour.</p>
<p>In a genuine emergency the Landlord may enter without notice.</p>'],
            ['Subletting and Assignment', '<p>The Tenant does not sublet, assign, or part with possession of the Premises, in whole or in part, without the Landlord\'s prior written consent.</p>'],
            ['Insurance and Liability', '<p>The Landlord insures the building. The Landlord does not insure the Tenant\'s belongings, and the Tenant is advised to arrange their own contents cover.</p>
<p>The Landlord is not liable for loss or damage to the Tenant\'s property except where it results from the Landlord\'s own negligence.</p>'],
            ['Termination and Notice', '<p>Either party may end this Agreement at or after the end of the Term by giving the other at least thirty (30) days\' written notice.</p>
<p>On vacating, the Tenant returns the Premises clean and in the same condition as at handover, fair wear and tear excepted, and returns all keys, remotes and access cards.</p>'],
            ['Default', '<p>The Landlord may treat the Tenant as in default if rent stays unpaid for fourteen (14) days after its due date, if the Tenant breaches any clause of this Agreement and fails to remedy it within fourteen (14) days of written notice, or if the Tenant abandons the Premises.</p>
<p>On default the Landlord may pursue any remedy available in law, including recovery of arrears and possession of the Premises.</p>'],
            ['Additional Terms', ltTermsBlock()],
            ['Governing Law and Disputes', ltGoverningBlock()],
            ['Entire Agreement', ltEntireBlock()],
        ]),
    ];

    // ------------------------------------------------------------------
    // 2. Simple One-Page Tenancy Agreement
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Simple One-Page Tenancy Agreement',
        'is_default' => 0,
        'content' => ltBuild('TENANCY AGREEMENT', [
            ['Parties and Premises', '<p><strong>Landlord:</strong> {{company_name}} &nbsp;|&nbsp; {{company_phone}}</p>
<p><strong>Tenant:</strong> {{tenant_name}} &nbsp;|&nbsp; {{tenant_phone}} &nbsp;|&nbsp; {{tenant_id_type}} {{tenant_id_number}}</p>
<p><strong>Premises:</strong> Unit {{unit_number}} ({{unit_type}}), {{property_name}}, {{property_address}}, {{property_city}}</p>'],
            ['Term and Rent', '<p>The tenancy runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong>.</p>
<p>Rent is <strong>{{rent_amount}}</strong> {{payment_frequency}}, paid in advance on or before day <strong>{{payment_due_day}}</strong> of each period.</p>
<p>Security deposit: <strong>{{deposit_amount}}</strong>, refundable within thirty (30) days of vacating, less any unpaid rent, unpaid utilities or damage beyond fair wear and tear.</p>'],
            ['What the Tenant Agrees', '<p>Pay rent on time and pay for electricity, water and refuse collection used at the Premises.</p>
<p>Use the Premises as a private home, keep it clean, and cause no nuisance to neighbours.</p>
<p>Report faults promptly, make no alterations without written consent, and do not sublet.</p>
<p>Return the Premises and all keys in good condition at the end of the tenancy.</p>'],
            ['What the Landlord Agrees', '<p>Give the Tenant quiet enjoyment of the Premises for the Term.</p>
<p>Keep the structure and the main water, electrical and sanitary installations in good repair.</p>
<p>Give at least twenty-four (24) hours\' notice before entering, except in an emergency.</p>'],
            ['Ending the Tenancy', '<p>Either party may end this tenancy by giving thirty (30) days\' written notice.</p>
<p>If rent stays unpaid for fourteen (14) days after its due date, the Landlord may take steps to recover the arrears and possession of the Premises.</p>'],
            ['Additional Terms', ltTermsBlock()],
        ]),
    ];

    // ------------------------------------------------------------------
    // 3. Single Room / Bedsitter Tenancy Agreement
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Single Room / Bedsitter Tenancy Agreement',
        'is_default' => 0,
        'content' => ltBuild('ROOM TENANCY AGREEMENT', [
            ['Parties', ltPartiesBlock()],
            ['The Room', ltPremisesBlock() . '
<p>The Tenant rents the room described above ("the Room") and has shared use of the common areas of the building — which may include the corridor, courtyard, water points, washing lines and shared sanitary facilities — together with the other occupants.</p>'],
            ['Term', ltTermBlock()],
            ['Rent', ltRentBlock()],
            ['Security Deposit', ltDepositBlock()],
            ['Water and Electricity', '<p>Where the Room has its own meter, the Tenant pays for their own consumption.</p>
<p>Where supply is shared, the Tenant pays the share notified by the Landlord in writing, and the Landlord makes the bills available for inspection on request.</p>
<p>The Tenant uses water and electricity reasonably and does not tamper with any meter or connection.</p>'],
            ['House Rules', '<p>Only the occupants declared to the Landlord may reside in the Room. Overnight guests staying more than three (3) consecutive nights require the Landlord\'s consent.</p>
<p>Common areas are kept clean and clear. Cooking is done only where the Landlord permits it.</p>
<p>Quiet hours apply between 10:00 pm and 6:00 am. Loud music, disorderly conduct and any unlawful activity are grounds for termination.</p>
<p>Refuse is placed only in the bins provided. Nothing is stored in corridors or stairways.</p>'],
            ['Care of the Room', '<p>The Tenant keeps the Room clean and reports leaks, electrical faults or breakages promptly.</p>
<p>The Tenant makes no alteration, drills no holes and changes no lock without written consent. The Landlord repairs the structure and the shared installations.</p>'],
            ['Access', '<p>The Landlord may enter the Room to inspect or repair after twenty-four (24) hours\' notice, and without notice in an emergency such as fire, flood or a burst pipe.</p>'],
            ['Security and Belongings', '<p>The Tenant is responsible for locking the Room and for their own belongings. The Landlord does not insure the Tenant\'s property and is not liable for theft from the Room or the common areas except where caused by the Landlord\'s negligence.</p>'],
            ['Ending the Tenancy', '<p>Either party may end this Agreement by giving thirty (30) days\' written notice.</p>
<p>The Landlord may end it immediately for non-payment of rent persisting fourteen (14) days beyond its due date, or for serious or repeated breach of the House Rules.</p>
<p>The Tenant hands back the Room clean and empty, with all keys returned.</p>'],
            ['Additional Terms', ltTermsBlock()],
            ['Entire Agreement', ltEntireBlock()],
        ]),
    ];

    // ------------------------------------------------------------------
    // 4. Furnished & Short-Stay Rental Agreement
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Furnished & Short-Stay Rental Agreement',
        'is_default' => 0,
        'content' => ltBuild('FURNISHED / SHORT-STAY RENTAL AGREEMENT', [
            ['Parties', ltPartiesBlock('Owner', 'Guest')],
            ['The Premises', ltPremisesBlock() . '
<p>The Premises are let fully furnished, with the furniture, appliances, linen, kitchenware and equipment set out in the signed inventory, which forms part of this Agreement.</p>'],
            ['Stay Period', '<p>The stay runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong>.</p>
<p>Check-in is from 2:00 pm on the start date and check-out is by 11:00 am on the end date, unless agreed otherwise in writing. Late check-out without agreement is charged at one additional night.</p>
<p>This is a short-stay licence to occupy furnished accommodation. It does not create a long-term or statutory tenancy, and it ends on the date shown above without further notice.</p>'],
            ['Charges', '<p>The rate is <strong>{{rent_amount}}</strong> per {{payment_frequency}} period, payable in advance.</p>
<p>Unless stated otherwise, the rate includes water, electricity, internet and routine housekeeping.</p>
<p>Extension of the stay is subject to availability and is confirmed in writing.</p>'],
            ['Security Deposit', '<p>A refundable deposit of <strong>{{deposit_amount}}</strong> is held against damage, breakage, missing items and excessive cleaning.</p>
<p>It is refunded within seven (7) days after check-out, once the inventory has been checked, less any deductions itemised in writing.</p>'],
            ['Occupancy', '<p>Only the guests declared at booking may occupy the Premises. The maximum number of occupants may not be exceeded.</p>
<p>Parties, events and commercial filming are not permitted. The Premises may not be re-let, sub-let or listed on any platform by the Guest.</p>'],
            ['Care of the Premises and Inventory', '<p>The Guest keeps the Premises and its contents in good order and reports any damage or fault immediately.</p>
<p>The Guest is responsible for the cost of items broken, damaged or missing at check-out, and for any specialist cleaning needed beyond normal use.</p>'],
            ['House Rules', '<p>No smoking indoors. Quiet hours apply between 10:00 pm and 7:00 am. Pets only with prior written consent.</p>
<p>The Guest observes any rules of the building or estate, including security, parking and access arrangements.</p>'],
            ['Access and Servicing', '<p>The Owner or housekeeping staff may enter at reasonable times for cleaning, servicing or repair, giving as much notice as is practical, and without notice in an emergency.</p>'],
            ['Cancellation and Early Departure', '<p>Cancellation terms are as notified at the time of booking. Where the Guest departs before the end date, charges for the booked period remain payable unless the Owner agrees otherwise in writing.</p>'],
            ['Liability', '<p>The Guest is responsible for their own belongings. The Owner does not insure them and is not liable for their loss except where caused by the Owner\'s negligence.</p>'],
            ['Additional Terms', ltTermsBlock()],
        ], 'Owner', 'Guest'),
    ];

    // ------------------------------------------------------------------
    // 5. Commercial Shop / Retail Lease Agreement
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Commercial Shop / Retail Lease Agreement',
        'is_default' => 0,
        'content' => ltBuild('COMMERCIAL SHOP / RETAIL LEASE AGREEMENT', [
            ['Parties', ltPartiesBlock('Landlord', 'Tenant')],
            ['The Premises', ltPremisesBlock() . '
<p>The Premises are let for retail trading, together with the right to use the common areas of the building in accordance with the rules notified by the Landlord.</p>'],
            ['Term', ltTermBlock()],
            ['Rent', ltRentBlock() . '
<p>Rent is exclusive of any value added tax or similar levy, which the Tenant pays in addition where it applies.</p>'],
            ['Security Deposit', ltDepositBlock()],
            ['Permitted Use', '<p>The Premises are used only for the trade the Tenant declared to the Landlord in writing, and for no other purpose without prior written consent.</p>
<p>The Tenant obtains and keeps current every licence, permit and registration that the trade requires, and produces copies to the Landlord on request.</p>
<p>The Tenant does not carry on any auction, illegal trade or activity that is a nuisance to other occupiers, or that invalidates the building insurance.</p>'],
            ['Service Charge and Outgoings', '<p>The Tenant pays electricity, water and telephone consumed at the Premises, and refuse removal for its own trade waste.</p>
<p>Where the building has a service charge covering security, common-area cleaning, lighting and maintenance, the Tenant pays the proportionate share notified in writing, and the Landlord provides a statement of the charge on request.</p>
<p>The Landlord pays land rates and statutory property taxes on the building.</p>'],
            ['Fit-Out, Signage and Alterations', '<p>The Tenant makes no structural alteration or shopfront change without the Landlord\'s prior written consent and, where required, the approval of the relevant authority.</p>
<p>Signage is displayed only in the position and to the standard the Landlord approves.</p>
<p>At the end of the Term the Tenant removes its trade fittings and signage, and makes good any damage caused by their removal.</p>'],
            ['Trading Hours and Presentation', '<p>The Tenant keeps the Premises open for trade during the hours reasonably notified by the Landlord for the building, and keeps the shopfront clean and properly presented.</p>'],
            ['Repairs and Condition', '<p>The Landlord keeps the structure, roof and main services in good repair.</p>
<p>The Tenant keeps the interior, shopfront, glazing and its own installations in good repair, and returns the Premises in that condition at the end of the Term, fair wear and tear excepted.</p>'],
            ['Insurance and Indemnity', '<p>The Landlord insures the building. The Tenant insures its own stock, fittings and equipment, and maintains public liability cover appropriate to its trade.</p>
<p>The Tenant indemnifies the Landlord against claims arising from the Tenant\'s use of the Premises, except where caused by the Landlord\'s negligence.</p>'],
            ['Assignment and Subletting', '<p>The Tenant does not assign, sublet, or share possession of the Premises without the Landlord\'s prior written consent, which is not unreasonably withheld for a financially sound assignee in the same trade.</p>'],
            ['Termination and Default', '<p>Either party may end this Lease at or after the end of the Term on sixty (60) days\' written notice.</p>
<p>The Landlord may terminate and re-enter where rent stays unpaid for twenty-one (21) days after its due date, where the Tenant breaches a clause and fails to remedy it within thirty (30) days of written notice, or where the Tenant becomes insolvent or ceases trading.</p>'],
            ['Additional Terms', ltTermsBlock()],
            ['Governing Law and Disputes', ltGoverningBlock()],
            ['Entire Agreement', ltEntireBlock()],
        ]),
    ];

    // ------------------------------------------------------------------
    // 6. Office Space Lease Agreement
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Office Space Lease Agreement',
        'is_default' => 0,
        'content' => ltBuild('OFFICE SPACE LEASE AGREEMENT', [
            ['Parties', ltPartiesBlock()],
            ['The Premises', ltPremisesBlock() . '
<p>The Premises are let as office accommodation, together with the right to use the building\'s entrances, lifts, staircases, washrooms and other common areas.</p>'],
            ['Term', ltTermBlock()],
            ['Rent', ltRentBlock() . '
<p>Rent is exclusive of any value added tax or similar levy, which the Tenant pays in addition where it applies.</p>'],
            ['Security Deposit', ltDepositBlock()],
            ['Permitted Use', '<p>The Premises are used only as business offices for the Tenant\'s declared activity, during normal business hours and at such other times as the building access rules allow.</p>
<p>The Tenant does not use the Premises for residential purposes, for storage of goods for sale, or for any activity that is a nuisance to other occupiers.</p>'],
            ['Service Charge and Utilities', '<p>The Tenant pays for electricity, water, telephone and internet consumed at the Premises, unless these are included in the service charge.</p>
<p>The Tenant pays the proportionate service charge for security, reception, common-area cleaning, lighting, lifts, generator fuel and grounds maintenance, as notified in writing.</p>'],
            ['Access, Security and Parking', '<p>Access cards, keys and remotes issued to the Tenant remain the Landlord\'s property and are returned at the end of the Term. Lost items are replaced at the Tenant\'s cost.</p>
<p>The Tenant observes the building\'s security and visitor procedures. Parking bays, where allocated, are used only by the Tenant and are not sublet.</p>'],
            ['Fit-Out and Alterations', '<p>Partitioning, cabling, air-conditioning and any other installation require the Landlord\'s prior written consent and are carried out to the standard the Landlord approves.</p>
<p>At the end of the Term the Tenant removes its own installations and reinstates the Premises, unless the Landlord agrees in writing that they may remain.</p>'],
            ['Repairs and Condition', '<p>The Landlord maintains the structure, common areas, lifts and main building services.</p>
<p>The Tenant keeps the interior of the Premises, including its own fittings and equipment, in good repair and reports faults promptly.</p>'],
            ['Insurance and Indemnity', '<p>The Landlord insures the building. The Tenant insures its own equipment, records and contents, and maintains public liability cover.</p>
<p>The Tenant indemnifies the Landlord against claims arising from the Tenant\'s use of the Premises, except where caused by the Landlord\'s negligence.</p>'],
            ['Assignment and Subletting', '<p>The Tenant does not assign, sublet or share occupation without the Landlord\'s prior written consent, save that the Tenant may allow occupation by a company within the same group on written notice to the Landlord.</p>'],
            ['Termination and Default', '<p>Either party may end this Lease at or after the end of the Term on sixty (60) days\' written notice.</p>
<p>The Landlord may terminate where rent stays unpaid for twenty-one (21) days after its due date, or where the Tenant breaches a clause and fails to remedy it within thirty (30) days of written notice.</p>'],
            ['Additional Terms', ltTermsBlock()],
            ['Governing Law and Disputes', ltGoverningBlock()],
            ['Entire Agreement', ltEntireBlock()],
        ]),
    ];

    // ------------------------------------------------------------------
    // 7. Warehouse / Godown Storage Lease Agreement
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Warehouse / Godown Storage Lease Agreement',
        'is_default' => 0,
        'content' => ltBuild('WAREHOUSE / GODOWN LEASE AGREEMENT', [
            ['Parties', ltPartiesBlock()],
            ['The Premises', ltPremisesBlock() . '
<p>The Premises are let for the storage and handling of goods, together with the right to use the yard and access roads for loading and unloading in accordance with the Landlord\'s directions.</p>'],
            ['Term', ltTermBlock()],
            ['Rent', ltRentBlock() . '
<p>Rent is exclusive of any value added tax or similar levy, which the Tenant pays in addition where it applies.</p>'],
            ['Security Deposit', ltDepositBlock()],
            ['Permitted Goods and Use', '<p>The Premises are used only for storing and handling lawful goods of the type the Tenant declared to the Landlord in writing.</p>
<p>The Tenant does <strong>not</strong> store explosives, fuel, compressed gas, corrosive or radioactive material, or any hazardous or perishable goods, without the Landlord\'s prior written consent and the approval of the relevant authority.</p>
<p>No manufacturing, workshop activity, cooking or residential occupation takes place at the Premises. No person sleeps at the Premises except a security guard approved in writing.</p>'],
            ['Loading Limits and Building Care', '<p>The Tenant observes the floor loading limits, stacking heights and rack fixing rules notified by the Landlord, and keeps gangways and fire exits clear at all times.</p>
<p>The Tenant does not overload the floor, roof or walls, and does not fix anything to the structure without written consent.</p>
<p>Forklifts and handling equipment are operated only by trained personnel, and the Tenant makes good any damage caused to floors, doors, walls or bollards.</p>'],
            ['Fire Safety and Compliance', '<p>The Tenant keeps firefighting equipment serviced and accessible, complies with all fire, health, safety and environmental requirements applying to its goods and operations, and permits inspection by the relevant authority.</p>
<p>The Tenant holds every licence and permit its operations require and produces copies to the Landlord on request.</p>'],
            ['Access Hours and Security', '<p>Access is available during the hours notified by the Landlord. Access outside those hours requires prior arrangement.</p>
<p>The Tenant is responsible for securing the Premises and its own goods, and observes the estate\'s gate, vehicle and visitor procedures.</p>'],
            ['Utilities and Outgoings', '<p>The Tenant pays for electricity and water consumed at the Premises and for the removal of its own waste.</p>
<p>Where the estate levies a service charge for security, road maintenance, drainage or common lighting, the Tenant pays the proportionate share notified in writing.</p>'],
            ['Repairs and Condition', '<p>The Landlord keeps the structure, roof, external walls and main services in good repair.</p>
<p>The Tenant keeps the interior, doors, shutters and its own installations in good repair, and returns the Premises swept clean and empty of all goods and waste at the end of the Term.</p>'],
            ['Insurance and Liability', '<p>The Landlord insures the building. <strong>The Landlord does not insure the Tenant\'s goods.</strong> The Tenant insures its own goods, equipment and liability to third parties for their full value.</p>
<p>Goods are stored at the Tenant\'s sole risk. The Landlord is not liable for loss of or damage to goods, including by theft, fire, water, pest or vermin, except where caused by the Landlord\'s negligence.</p>
<p>The Tenant indemnifies the Landlord against claims arising from the Tenant\'s goods or operations.</p>'],
            ['Assignment and Subletting', '<p>The Tenant does not assign, sublet, or allow any third party to store goods at the Premises without the Landlord\'s prior written consent.</p>'],
            ['Termination and Default', '<p>Either party may end this Lease at or after the end of the Term on sixty (60) days\' written notice.</p>
<p>The Landlord may terminate where rent stays unpaid for twenty-one (21) days after its due date, or where prohibited goods are stored at the Premises.</p>
<p>Goods left at the Premises more than fourteen (14) days after the Term ends may be removed and stored at the Tenant\'s cost, and dealt with as the law allows.</p>'],
            ['Additional Terms', ltTermsBlock()],
            ['Governing Law and Disputes', ltGoverningBlock()],
        ]),
    ];

    // ------------------------------------------------------------------
    // 8. Hostel / Student Accommodation Agreement
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Hostel / Student Accommodation Agreement',
        'is_default' => 0,
        'content' => ltBuild('HOSTEL / STUDENT ACCOMMODATION AGREEMENT', [
            ['Parties', ltPartiesBlock('Hostel Management', 'Resident')],
            ['The Accommodation', ltPremisesBlock() . '
<p>The Resident is allocated the bed space and room shown above. Management may reallocate the Resident to comparable accommodation where this is reasonably necessary, on written notice.</p>
<p>The Resident has shared use of the common facilities — which may include study areas, kitchen, laundry, bathrooms and grounds — with the other residents.</p>'],
            ['Period of Residence', '<p>Residence runs from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong>, covering the academic period stated by Management.</p>
<p>This Agreement is personal to the Resident. It grants a licence to occupy a bed space and does not create a tenancy of any particular room.</p>'],
            ['Fees', '<p>The accommodation fee is <strong>{{rent_amount}}</strong> per {{payment_frequency}} period, payable in advance on or before day <strong>{{payment_due_day}}</strong>.</p>
<p>Fees are payable whether or not the Resident is in occupation during the period, including during short vacations falling within the Term.</p>'],
            ['Caution Deposit', '<p>A refundable caution deposit of <strong>{{deposit_amount}}</strong> is held against damage, loss of keys, unpaid fees and breach of the Hostel Rules.</p>
<p>It is refunded within thirty (30) days after the Resident checks out and returns all keys, less any deductions itemised in writing.</p>'],
            ['Hostel Rules', '<p>Quiet and study hours apply as posted. Noise that disturbs other residents is not permitted at any time.</p>
<p>Visitors are received only in the designated areas and during visiting hours. Overnight guests are not permitted.</p>
<p>Alcohol, illegal drugs, weapons, cooking appliances in bedrooms, open flames and smoking indoors are prohibited.</p>
<p>Residents do not swap rooms or bed spaces, and do not admit any person who is not a registered resident.</p>
<p>Management may post additional rules, which form part of this Agreement once notified.</p>'],
            ['Care of Property', '<p>The Resident keeps the room and shared facilities clean, reports faults promptly, and does not remove or alter any furniture or fitting.</p>
<p>Damage to the room, its furniture or common property is charged to the Resident responsible, or shared among the residents of the room where responsibility cannot be established.</p>'],
            ['Health, Safety and Access', '<p>The Resident complies with all fire and safety instructions and takes part in evacuation drills.</p>
<p>Management may enter any room for inspection, cleaning, repair, or where there is reasonable concern for safety or a breach of the Rules, giving notice where it is practical to do so.</p>'],
            ['Belongings', '<p>The Resident is responsible for their own belongings and for securing them. Management does not insure them and is not liable for loss or theft except where caused by its own negligence.</p>'],
            ['Discipline and Termination', '<p>Management may issue a warning, impose a charge, or terminate this Agreement and require the Resident to vacate where the Resident seriously or repeatedly breaches the Hostel Rules, fails to pay fees, or behaves in a way that endangers or harasses others.</p>
<p>The Resident may end this Agreement on thirty (30) days\' written notice; fees already due remain payable.</p>'],
            ['Emergency Contact and Guardian', '<p>The Resident provides an emergency contact and, where the Resident is under the age of majority, a parent or guardian countersigns this Agreement.</p>
<p>Management may contact that person in a medical emergency or in a serious disciplinary matter.</p>'],
            ['Additional Terms', ltTermsBlock()],
        ], 'Hostel Management', 'Resident'),
    ];

    // ------------------------------------------------------------------
    // 9. Plot / Land Lease Agreement
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Plot / Land Lease Agreement',
        'is_default' => 0,
        'content' => ltBuild('PLOT / LAND LEASE AGREEMENT', [
            ['Parties', ltPartiesBlock('Lessor', 'Lessee')],
            ['The Land', ltPremisesBlock() . '
<p>The land let under this Agreement ("the Land") is identified above and is delineated in the sketch or survey plan attached to and forming part of this Agreement.</p>
<p>The Lessee accepts the Land in its present condition, having inspected it and satisfied itself as to its boundaries, access and suitability.</p>'],
            ['Term', ltTermBlock()],
            ['Rent', ltRentBlock()],
            ['Security Deposit', ltDepositBlock()],
            ['Permitted Use', '<p>The Land is used only for the purpose the Lessee declared to the Lessor in writing, and for no other purpose without prior written consent.</p>
<p>The Lessee does not use the Land for any unlawful purpose, for dumping of waste, for quarrying or extraction of soil or sand, or for any activity that damages the Land or is a nuisance to neighbouring occupiers.</p>'],
            ['Structures and Improvements', '<p>The Lessee erects no permanent structure on the Land without the Lessor\'s prior written consent and the approval of the relevant planning authority.</p>
<p>Temporary structures approved by the Lessor are removed by the Lessee at the end of the Term, and the Land restored to its former condition.</p>
<p>Any permanent improvement remaining at the end of the Term becomes the property of the Lessor without compensation, unless the parties agree otherwise in writing.</p>'],
            ['Boundaries and Fencing', '<p>The Lessee does not move, remove or interfere with any boundary mark, beacon or fence.</p>
<p>Where the Lessee fences the Land, the fence is erected on the correct boundary and maintained in good condition throughout the Term.</p>'],
            ['Rates, Taxes and Outgoings', '<p>The Lessor pays land rent payable to the authority and any statutory land taxes on the title.</p>
<p>The Lessee pays for water, electricity and any service connected for its own use, and for the removal of its own waste.</p>'],
            ['Care of the Land', '<p>The Lessee keeps the Land clean, free of refuse and free of overgrowth, and takes reasonable steps to prevent erosion, contamination and damage to any tree the Lessor has asked to be preserved.</p>
<p>The Lessee makes good any contamination or damage it causes.</p>'],
            ['Title and Quiet Enjoyment', '<p>The Lessor confirms it is entitled to let the Land and will give the Lessee quiet enjoyment of it for the Term, provided the Lessee observes this Agreement.</p>
<p>This Agreement creates a lease only. It transfers no ownership, and it gives the Lessee no right to sell, charge or subdivide the Land.</p>'],
            ['Assignment and Subletting', '<p>The Lessee does not assign, sublet or part with possession of the Land, in whole or in part, without the Lessor\'s prior written consent.</p>'],
            ['Termination and Default', '<p>Either party may end this Agreement at or after the end of the Term on sixty (60) days\' written notice.</p>
<p>The Lessor may terminate where rent stays unpaid for thirty (30) days after its due date, or where the Lessee breaches a clause and fails to remedy it within thirty (30) days of written notice.</p>
<p>On termination the Lessee vacates the Land, removes its property, and leaves the Land clean and clear.</p>'],
            ['Additional Terms', ltTermsBlock()],
            ['Governing Law and Disputes', ltGoverningBlock()],
            ['Entire Agreement', ltEntireBlock()],
        ], 'Lessor', 'Lessee'),
    ];

    // ------------------------------------------------------------------
    // 10. Lease Renewal & Extension Addendum
    // ------------------------------------------------------------------
    $templates[] = [
        'name' => 'Lease Renewal & Extension Addendum',
        'is_default' => 0,
        'content' => ltBuild('LEASE RENEWAL &amp; EXTENSION ADDENDUM', [
            ['Parties', '<p><strong>Landlord:</strong> {{company_name}}<br>
Address: {{company_address}}<br>
Phone: {{company_phone}} &nbsp;|&nbsp; Email: {{company_email}}</p>
<p><strong>Tenant:</strong> {{tenant_name}}<br>
Phone: {{tenant_phone}} &nbsp;|&nbsp; Email: {{tenant_email}}<br>
Identification: {{tenant_id_type}} — {{tenant_id_number}}</p>'],
            ['The Premises', ltPremisesBlock()],
            ['Purpose of this Addendum', '<p>This Addendum is made on <strong>{{date_today}}</strong> and renews and extends the existing lease between the parties for the Premises described above ("the Original Lease").</p>
<p>It is read together with the Original Lease and forms part of it.</p>'],
            ['Renewed Term', '<p>The Original Lease is extended for a further term running from <strong>{{start_date}}</strong> to <strong>{{end_date}}</strong>.</p>'],
            ['Revised Rent', '<p>With effect from <strong>{{start_date}}</strong>, rent is <strong>{{rent_amount}}</strong>, payable <strong>{{payment_frequency}}</strong> in advance, on or before day <strong>{{payment_due_day}}</strong> of each payment period.</p>
<p>All other provisions of the Original Lease relating to payment of rent, receipts and late fees continue to apply.</p>'],
            ['Security Deposit', '<p>The security deposit held under the Original Lease is <strong>{{deposit_amount}}</strong>, and is carried forward and held on the same terms for the renewed term.</p>
<p>Where the revised rent requires an increased deposit, the Tenant pays the difference on or before the start of the renewed term.</p>'],
            ['Condition of the Premises', '<p>The Tenant confirms the Premises are in good condition and that any outstanding repair items have been notified to the Landlord in writing before signing this Addendum.</p>'],
            ['All Other Terms Unchanged', '<p>Except as varied by this Addendum, every term and condition of the Original Lease remains in full force and continues to bind the parties.</p>
<p>Where this Addendum conflicts with the Original Lease, this Addendum prevails.</p>'],
            ['Additional Terms', ltTermsBlock()],
        ]),
    ];

    return $templates;
}
