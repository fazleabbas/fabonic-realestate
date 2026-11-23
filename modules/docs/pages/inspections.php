<h1><i class="bi bi-clipboard-check me-2"></i><?php echo __('docs_inspections_guide', 'Inspections'); ?></h1>
<p><?php echo __('docs_insp_intro', 'The Inspections module handles move-in, move-out, and routine property inspections. Document the condition of each unit with room-by-room checklists and photos.'); ?></p>

<h2><?php echo __('docs_insp_types', 'Inspection Types'); ?></h2>
<ul>
    <li><strong><?php echo __('docs_insp_movein', 'Move-in'); ?></strong> — <?php echo __('docs_insp_movein_desc', 'Conducted when a tenant moves in. Documents the initial condition of the unit.'); ?></li>
    <li><strong><?php echo __('docs_insp_moveout', 'Move-out'); ?></strong> — <?php echo __('docs_insp_moveout_desc', 'Conducted when a tenant leaves. Compared against the move-in inspection to identify damages.'); ?></li>
    <li><strong><?php echo __('docs_insp_routine', 'Routine'); ?></strong> — <?php echo __('docs_insp_routine_desc', 'Periodic inspections during the tenancy.'); ?></li>
</ul>

<h2><?php echo __('docs_insp_templates', 'Inspection Templates'); ?></h2>
<p><?php echo __('docs_insp_templates_desc', 'Create reusable templates that define which rooms and items to inspect. Each template contains a list of rooms, and each room contains items to check (walls, floor, ceiling, doors, windows, fixtures, appliances).'); ?></p>

<h2><?php echo __('docs_insp_conducting', 'Conducting an Inspection'); ?></h2>
<ol>
    <li><?php echo __('docs_insp_cstep1', 'Go to <strong>Inspections</strong> and click <strong>New Inspection</strong>'); ?></li>
    <li><?php echo __('docs_insp_cstep2', 'Select the unit, tenant, and inspection type'); ?></li>
    <li><?php echo __('docs_insp_cstep3', 'Choose a template or use the default'); ?></li>
    <li><?php echo __('docs_insp_cstep4', 'Rate each item: Excellent, Good, Fair, Poor, Damaged, or Missing'); ?></li>
    <li><?php echo __('docs_insp_cstep5', 'Upload photos for each item as evidence'); ?></li>
    <li><?php echo __('docs_insp_cstep6', 'Add notes and mark the inspection as completed'); ?></li>
</ol>

<h2><?php echo __('docs_insp_comparison', 'Move-in vs Move-out Comparison'); ?></h2>
<p><?php echo __('docs_insp_comparison_desc', 'When creating a move-out inspection, the system pre-populates items from the move-in inspection. The report shows a side-by-side comparison highlighting any deterioration. This directly links to deposit deductions.'); ?></p>

<h2><?php echo __('docs_insp_report', 'Inspection Reports'); ?></h2>
<p><?php echo __('docs_insp_report_desc', 'Generate printable PDF reports with condition ratings, photos, and comparison data. Reports include inspector signature fields and condition legends.'); ?></p>
