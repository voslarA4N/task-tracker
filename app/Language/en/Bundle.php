<?php

declare(strict_types=1);

return [
    'navTitle'         => 'Accountant bundle',
    'navHint'          => 'ZIP with PDF documents for a period',
    'title'            => 'Accountant bundle',
    'intro'            => 'ZIP with PDFs of invoices and credit notes and a CSV list of documents. Documents are selected by delivery date. Work reports are not included.',
    'backToMore'       => 'Back to More',
    'entityLabel'      => 'Entity',
    'fromLabel'        => 'From month',
    'toLabel'          => 'To month',
    'rangeHint'        => 'At most 12 months.',
    'previewCounts'    => '{0} documents: {1} invoices, {2} credit notes',
    'previewCancelled' => 'of which {0} cancelled',
    'previewTurnover'  => 'Turnover for the period {0}',
    'empty'            => 'There are no documents in the selected period.',
    'download'         => 'Download bundle',
    'preparing'        => 'Preparing the bundle…',
    'externalNote'     => 'External invoices (issued outside the app) are not included in the bundle.',
    'errorOrder'       => 'The "From" month must not be later than the "To" month.',
    'errorTooLong'     => 'The period can be at most 12 months.',
    'errorRange'       => 'The period must be between January 2000 and December 2100. Adjust the months and try again.',
    'errorMissingPdf'  => 'The stored PDF is missing for {0} documents: {1}. The bundle was not created.',
    'errorTooLarge'    => 'The bundle is too large. Choose a shorter period.',
    'errorBuild'       => 'The bundle could not be created. Try a shorter period or try again.',
];
