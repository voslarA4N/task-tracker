<?php

declare(strict_types=1);

return [
    'navTitle'     => 'Documents',
    'title'        => 'Document regeneration',
    'intro'        => 'A work report can be regenerated at any time. Invoice PDFs only within a window after issuing.',
    'fieldLabel'   => 'Invoice PDF window',
    'fieldHint'    => 'Whole number 0 to 8760. 0 means invoice PDFs cannot be regenerated. A change applies only to invoices issued after it; already issued invoices keep their own window.',
    'fieldUnit'    => 'hours',
    'save'         => 'Save',
    'saved'        => 'Settings saved.',
    'errorInvalid' => 'Enter a whole number from 0 to 8760.',
    'errorStale'   => 'Someone else changed the setting in the meantime. The saved value is {0} h; your entered value was kept in the form. Check it and save again.',
    'back'         => 'Back',
];
