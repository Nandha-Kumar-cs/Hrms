<?php
/**
 * Promotion ⇄ Letter synchronisation.
 *
 * A promotion is created in exactly ONE place — the promotion LETTER workflow
 * (Employees → Promotion → New Letter). When such a letter is created, this
 * helper mirrors it into the promotion history table (employee_promotions) and
 * applies the new designation/department to the employee profile — mirroring the
 * reference Laravel PromotionController@store.
 *
 * The link is the employee_promotions.letter_id column, which guarantees a
 * single history row per letter (no duplicates) and lets letter issue/delete
 * keep the history row in sync.
 *
 * Requires install/add_promotion_sync_fields.sql.
 */

/**
 * Create or update the promotion-history row for a promotion letter, and apply
 * the promotion to the employee profile. Returns the employee_promotions id.
 *
 * $data keys: effective_date, previous_designation_id, new_designation_id,
 *             department_id, salary_revision, remarks
 */
function promotion_sync_from_letter(
    PDO $db,
    int $letterId,
    int $empId,
    array $data,
    string $reference,
    int $createdBy,
    string $status,
    string $promotionDate
): int {
    $prevDesig = !empty($data['previous_designation_id']) ? (int)$data['previous_designation_id'] : null;
    $newDesig  = !empty($data['new_designation_id'])      ? (int)$data['new_designation_id']      : null;
    $deptId    = !empty($data['department_id'])           ? (int)$data['department_id']           : null;
    $effective = !empty($data['effective_date']) ? $data['effective_date'] : $promotionDate;
    $salary    = ($data['salary_revision'] !== null && $data['salary_revision'] !== '')
                 ? (float)$data['salary_revision'] : null;
    $remarks   = !empty($data['remarks']) ? $data['remarks'] : null;

    // One history row per letter — update in place if it already exists (no dupes).
    $find = $db->prepare('SELECT id FROM employee_promotions WHERE letter_id = ? LIMIT 1');
    $find->execute([$letterId]);
    $promoId = (int)$find->fetchColumn();

    if ($promoId) {
        $db->prepare(
            'UPDATE employee_promotions
                SET employee_id=?, effective_date=?, promotion_date=?,
                    previous_designation_id=?, new_designation_id=?, department_id=?,
                    salary_revision=?, remarks=?, status=?, letter_reference=?, created_by=?
              WHERE id=?'
        )->execute([
            $empId, $effective, $promotionDate, $prevDesig, $newDesig, $deptId,
            $salary, $remarks, $status, $reference, $createdBy, $promoId,
        ]);
    } else {
        $db->prepare(
            'INSERT INTO employee_promotions
                (employee_id, letter_id, effective_date, promotion_date,
                 previous_designation_id, new_designation_id, department_id,
                 salary_revision, remarks, status, letter_reference, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $empId, $letterId, $effective, $promotionDate, $prevDesig, $newDesig, $deptId,
            $salary, $remarks, $status, $reference, $createdBy,
        ]);
        $promoId = (int)$db->lastInsertId();
    }

    // Apply the promotion to the employee profile: always set the new designation;
    // set the new department only when one was chosen (mirrors the reference).
    if ($newDesig) {
        if ($deptId) {
            $db->prepare('UPDATE employees SET designation_id=?, department_id=? WHERE id=?')
               ->execute([$newDesig, $deptId, $empId]);
        } else {
            $db->prepare('UPDATE employees SET designation_id=? WHERE id=?')
               ->execute([$newDesig, $empId]);
        }
    }

    return $promoId;
}

/** Keep the history row's status aligned with the letter (e.g. on Issue). */
function promotion_sync_status(PDO $db, int $letterId, string $status): void
{
    $db->prepare('UPDATE employee_promotions SET status=? WHERE letter_id=?')
       ->execute([$status, $letterId]);
}

/** Remove the history row when its promotion letter is deleted. */
function promotion_sync_delete(PDO $db, int $letterId): void
{
    $db->prepare('DELETE FROM employee_promotions WHERE letter_id=?')->execute([$letterId]);
}

/** Parse a free-text salary (e.g. "₹ 15,000" / "15000.50") into a number or null. */
function promotion_parse_salary(?string $v): ?float
{
    if ($v === null) return null;
    $n = preg_replace('/[^0-9.]/', '', $v);
    return ($n === '' ) ? null : (float)$n;
}
