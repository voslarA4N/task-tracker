<?php

declare(strict_types=1);

return [
    'errorForm'         => 'The form contains errors. Fix the marked fields and save again.',
    'errorClient'       => 'The selected client is not available. Choose another client.',
    'errorTitle'        => 'Enter the task title.',
    'errorHours'        => 'Enter hours as a number greater than 0 with at most 2 decimals, e.g. 8 or 8.5.',
    'errorRate'         => 'Enter the rate as a number, e.g. 27 or 27.50.',
    'errorUrl'          => 'The link must start with http:// or https://.',
    'errorNote'         => 'The note can have at most 2000 characters.',
    'errorClientLocked' => 'The client cannot be changed while the task has billing parts.',
    'errorHasLocked'    => 'The task has invoiced parts and cannot be deleted.',
    'notFound'          => 'Task not found. Return to the task list.',
    'client'            => 'Client',
    'url'               => 'Task link',
    'title'             => 'Title',
    'hours'             => 'Quoted hours',
    'rate'              => 'Rate',
    'amount'            => 'Quoted amount',
    'note'              => 'Note',
    'status'            => 'Status',
    'createdAt'         => 'Created',
    'doneAt'            => 'Completed',
    'save'              => 'Save task',
    'saved'             => 'Task saved.',
    'deleted'           => 'Task deleted.',
    'amountHint'        => 'The amount is indicative only, the server computes the final value.',
    'currencyHint'      => 'The task currency is the client currency: {0}.',
];
