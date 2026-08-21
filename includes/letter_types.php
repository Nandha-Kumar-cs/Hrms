<?php
/**
 * Letter type registry — the single list of letter types the Letters module
 * supports, shared by the list, create form and PDF download so the four
 * places never drift apart.
 *
 * Each type maps 1:1 to a value of the `letters`.`type` ENUM, to a
 * `letters`.<action> permission (lower-cased), and to a reference-number code.
 */

/** ENUM values of letters.type, in display order. */
const LETTER_TYPES = ['Offer', 'Confirmation', 'Increment', 'Promotion', 'Experience', 'Internship'];

/**
 * Reference-number code used in HR/<code>/<year>/<seq>. The original four keep
 * their single-letter codes (existing references stay consistent); the newer
 * types use three-letter codes because "I" was already taken by Increment.
 */
const LETTER_REF_CODES = [
    'Offer'        => 'O',
    'Confirmation' => 'C',
    'Increment'    => 'I',
    'Promotion'    => 'P',
    'Experience'   => 'EXP',
    'Internship'   => 'INT',
];

/** Human label for a type — most are "<Type> Letter", internships are certificates. */
const LETTER_TYPE_LABELS = [
    'Offer'        => 'Offer Letter',
    'Confirmation' => 'Confirmation Letter',
    'Increment'    => 'Increment Letter',
    'Promotion'    => 'Promotion Letter',
    'Experience'   => 'Experience Letter',
    'Internship'   => 'Internship Certificate',
];

/** Display label for a letter type, e.g. "Internship Certificate". */
function letter_type_label(string $type): string
{
    return LETTER_TYPE_LABELS[$type] ?? ($type . ' Letter');
}

/** Filename-safe document name used for the downloaded PDF. */
function letter_type_filename(string $type): string
{
    return str_replace(' ', '_', letter_type_label($type));
}

/**
 * Letter types an employee may be issued — interns get the Internship
 * Certificate and nothing else. The "is this an intern" rule itself lives in
 * includes/helpers.php (employee_is_intern), because the employee form uses the
 * same rule to disable the CTC input.
 */
function letter_types_for_employee(?array $emp): array
{
    return employee_is_intern($emp) ? ['Internship'] : LETTER_TYPES;
}
