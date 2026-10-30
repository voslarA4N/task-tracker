<?php

declare(strict_types=1);

return [
    'cardTitle'         => 'Exchange rate',
    'rateLine'          => '{0} rate: {1}',
    'metaFrom'          => 'from {0}',
    'metaTable'         => 'table {0}',
    'basisDelivery'     => 'based on the delivery date',
    'basisIssue'        => 'based on the issue date',
    'rulePrevious'      => 'previous business day',
    'ruleOnOrBefore'    => 'rate valid on the day, otherwise the last one before it',
    'totalIn'           => 'Total in {0}',
    'calcMultiply'      => 'converted from {0} by multiplying by the rate, rounded to 2 decimal places',
    'calcDivide'        => 'converted from {0} by dividing by the rate, rounded to 2 decimal places',
    'loading'           => 'Loading rate…',
    'unavailableTitle'  => 'Exchange rate not available yet',
    'unavailableBody'   => 'The {0} rate could not be loaded. Enter the rate manually or try loading it again.',
    'unsupportedBody'   => '{0} does not provide a rate for this currency pair ({1} and {2}). The rate will always have to be entered manually.',
    'pendingTotal'      => 'Total in {0}: will be known once the rate is entered.',
    'issueHintRetry'    => 'Issuing requires a rate. Enter it manually or try loading it again.',
    'issueHintManual'   => 'Issuing requires a rate. Enter it manually.',
    'settingsLink'      => 'Change the rate source and date in settings',
    'confirmRate'       => 'Rate',
    'confirmRateSource' => '{0} ({1}, from {2})',
    'badgeManual'       => 'Manual rate',
];
