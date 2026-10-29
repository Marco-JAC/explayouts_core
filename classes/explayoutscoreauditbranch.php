<?php
/**
 * explayouts_core's branch of the audit taxonomy, registered in
 * settings/audit.ini.append.php ([AuditEventSettings] Branches[explayouts_core]).
 * The kernel's catalogue has no names for layouts transfers, so the subject
 * starts with the extension's name, as the taxonomy asks.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 */
class expLayoutsCoreAuditBranch implements expAuditTaxonomyBranch
{
    public function events()
    {
        return array(
            // A layouts transfer file was made: after = the selection and the counts of the manifest.
            'data.explayouts_core.export' => array( 'label' => 'Layouts export', 'severity' => 'info', 'channel' => 'system', 'default' => 'on' ),
            // A layouts transfer file was imported (not a dry run): after = strategy, publish, the layouts and rules
            // written; result failed with the error when the transaction was rolled back.
            'data.explayouts_core.import' => array( 'label' => 'Layouts import', 'severity' => 'notice', 'channel' => 'system', 'default' => 'on' ),
            // One layout or rule the import wrote, below the import's event: object = the item, after = what was done.
            'data.explayouts_core.import.item' => array( 'label' => 'Layouts import item', 'severity' => 'info', 'channel' => 'system', 'default' => 'on' ),
        );
    }
}
