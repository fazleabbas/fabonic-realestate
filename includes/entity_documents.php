<?php
/**
 * FIS Real Estate — Attach documents to any record
 *
 * The Documents module can already link a file to a payment, deposit,
 * remittance or expense; what was missing was a way to do it from the record's
 * own page. This renders that card, so a deposit slip goes on the deposit and a
 * supplier invoice on the expense, instead of everything landing in one pile.
 *
 * Gated exactly as the Documents module is: Professional tier, module enabled,
 * and the documents permission. Where it is unavailable the card simply does
 * not appear.
 */

/** Is the Documents module usable by this user right now? */
function entityDocsActive() {
    static $active = null;
    if ($active !== null) return $active;
    $active = hasTier('professional')
        && getSetting('documents_enabled', '0') === '1'
        && hasPermission('documents', 'view');
    return $active;
}

/** Documents attached to one record. */
function entityDocuments($entityType, $entityId) {
    global $conn;
    if (!entityDocsActive() || !$entityId) return [];

    try {
        $stmt = $conn->prepare("
            SELECT d.id, d.title, d.file_name, d.file_size, d.created_at, d.is_confidential,
                   c.name AS category_name
            FROM documents d
            LEFT JOIN document_categories c ON c.id = d.category_id
            WHERE d.entity_type = :type AND d.entity_id = :id AND d.status = 'active'
            ORDER BY d.created_at DESC
        ");
        $stmt->execute(['type' => $entityType, 'id' => (int) $entityId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // Older tenants may not have the newer entity types in the enum yet.
        return [];
    }
}

/** How many documents are attached — for a badge next to an Attach button. */
function entityDocumentsCount($entityType, $entityId) {
    global $conn;
    if (!entityDocsActive() || !$entityId) return 0;
    try {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM documents
                                WHERE entity_type = ? AND entity_id = ? AND status = 'active'");
        $stmt->execute([$entityType, (int) $entityId]);
        return (int) $stmt->fetchColumn();
    } catch (PDOException $e) {
        return 0;
    }
}

/** Link that opens the upload form with this record pre-selected. */
function entityUploadUrl($entityType, $entityId) {
    return BASE_URL . 'modules/documents/upload.php?entity_type=' . urlencode($entityType)
         . '&entity_id=' . (int) $entityId;
}

/**
 * A compact Attach button with a count, for use inside a table row where a full
 * card would not fit.
 */
function entityDocsButton($entityType, $entityId, $title = null) {
    if (!entityDocsActive()) return '';
    $count = entityDocumentsCount($entityType, $entityId);
    $title = $title ?: __('doc_attach', 'Attach a document');

    $badge = $count > 0
        ? '<span class="badge bg-secondary ms-1">' . $count . '</span>'
        : '';

    return '<a href="' . htmlspecialchars(entityUploadUrl($entityType, $entityId)) . '" '
         . 'class="btn btn-sm btn-outline-secondary" title="' . htmlspecialchars($title) . '">'
         . '<i class="bi bi-paperclip"></i>' . $badge . '</a>';
}

/**
 * The full documents card for a record's detail page.
 *
 * @param array $opts  title, empty_text, upload_label
 */
function renderEntityDocuments($entityType, $entityId, $opts = []) {
    if (!entityDocsActive()) return;

    $docs = entityDocuments($entityType, $entityId);
    $title       = $opts['title']        ?? __('doc_documents', 'Documents');
    $emptyText   = $opts['empty_text']   ?? __('doc_none_attached', 'Nothing attached yet');
    $uploadLabel = $opts['upload_label'] ?? __('doc_attach', 'Attach a document');
    $canUpload   = hasPermission('documents', 'create');
    ?>
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-paperclip me-1"></i> <?php echo htmlspecialchars($title); ?> (<?php echo count($docs); ?>)</span>
            <?php if ($canUpload): ?>
            <a href="<?php echo htmlspecialchars(entityUploadUrl($entityType, $entityId)); ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-cloud-upload me-1"></i> <?php echo htmlspecialchars($uploadLabel); ?>
            </a>
            <?php endif; ?>
        </div>
        <?php if (!$docs): ?>
        <div class="card-body text-center text-muted py-4">
            <i class="bi bi-paperclip" style="font-size:2rem;opacity:.4;"></i>
            <p class="mt-2 mb-0"><?php echo htmlspecialchars($emptyText); ?></p>
        </div>
        <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0 align-middle">
                <thead>
                    <tr>
                        <th><?php _e('doc_title', 'Title'); ?></th>
                        <th class="d-none d-md-table-cell"><?php _e('doc_category', 'Category'); ?></th>
                        <th class="text-end d-none d-md-table-cell"><?php _e('doc_size', 'Size'); ?></th>
                        <th class="d-none d-lg-table-cell"><?php _e('date'); ?></th>
                        <th class="text-end" style="width:90px;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($docs as $doc):
                        $bytes = (int) $doc['file_size'];
                        if ($bytes >= 1048576)  $size = round($bytes / 1048576, 1) . ' MB';
                        elseif ($bytes >= 1024) $size = round($bytes / 1024, 1) . ' KB';
                        else                    $size = $bytes . ' B';
                    ?>
                    <tr>
                        <td>
                            <a href="<?php echo BASE_URL; ?>modules/documents/view.php?id=<?php echo (int) $doc['id']; ?>">
                                <?php echo htmlspecialchars($doc['title']); ?>
                            </a>
                            <?php if (!empty($doc['is_confidential'])): ?>
                            <i class="bi bi-lock-fill text-warning ms-1" title="<?php _e('doc_confidential', 'Confidential'); ?>"></i>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted d-none d-md-table-cell"><?php echo htmlspecialchars($doc['category_name'] ?? '—'); ?></td>
                        <td class="text-end small text-muted d-none d-md-table-cell"><?php echo $size; ?></td>
                        <td class="small text-muted d-none d-lg-table-cell"><?php echo formatDate($doc['created_at']); ?></td>
                        <td class="text-end">
                            <a href="<?php echo BASE_URL; ?>api/documents.php?action=download&id=<?php echo (int) $doc['id']; ?>"
                               class="btn btn-sm btn-outline-primary" title="<?php _e('download'); ?>">
                                <i class="bi bi-download"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
    <?php
}
