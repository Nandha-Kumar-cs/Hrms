# -*- coding: utf-8 -*-
"""
Builds "MagDyn HRMS - Complete Salary Calculation" reference PDF.
Content is derived from the actual code:
  includes/PayrollCalculator.php, includes/payroll_extras.php,
  includes/helpers.php, config/app.php, modules/payroll/*.php
NOTE: only WinAnsi-safe characters (no Rupee sign, no arrows/>=) - Helvetica
lacks those glyphs and would render as black boxes.
"""
from reportlab.lib.pagesizes import A4
from reportlab.lib.units import mm
from reportlab.lib import colors
from reportlab.lib.styles import getSampleStyleSheet, ParagraphStyle
from reportlab.lib.enums import TA_CENTER, TA_LEFT
from reportlab.platypus import (SimpleDocTemplate, Paragraph, Spacer, Table,
                                TableStyle, PageBreak, KeepTogether)

import os
OUT = os.path.join(os.path.dirname(os.path.abspath(__file__)),
                   "HRMS_Complete_Salary_Calculation.pdf")

NAVY   = colors.HexColor("#1e3a8a")
BLUE   = colors.HexColor("#1a6fb5")
GREEN  = colors.HexColor("#166534")
RED    = colors.HexColor("#991b1b")
GREY   = colors.HexColor("#475569")
LIGHT  = colors.HexColor("#f1f5f9")
LINE   = colors.HexColor("#c8d8e8")

ss = getSampleStyleSheet()

H1 = ParagraphStyle("H1", parent=ss["Heading1"], fontName="Helvetica-Bold",
                    fontSize=15, textColor=NAVY, spaceBefore=14, spaceAfter=7,
                    leading=18)
H2 = ParagraphStyle("H2", parent=ss["Heading2"], fontName="Helvetica-Bold",
                    fontSize=11.5, textColor=BLUE, spaceBefore=10, spaceAfter=4,
                    leading=14)
BODY = ParagraphStyle("BODY", parent=ss["Normal"], fontName="Helvetica",
                      fontSize=9.3, leading=13.2, spaceAfter=5,
                      textColor=colors.HexColor("#1f2937"))
BULLET = ParagraphStyle("BULLET", parent=BODY, leftIndent=12, bulletIndent=3,
                        spaceAfter=2.5)
SMALL = ParagraphStyle("SMALL", parent=BODY, fontSize=8.1, leading=11,
                       textColor=GREY)
CELL = ParagraphStyle("CELL", parent=BODY, fontSize=8.3, leading=10.6, spaceAfter=0)
CELLB = ParagraphStyle("CELLB", parent=CELL, fontName="Helvetica-Bold")
MONO = ParagraphStyle("MONO", parent=BODY, fontName="Courier-Bold", fontSize=8.6,
                      leading=12.4, textColor=colors.HexColor("#0f172a"))
TITLE = ParagraphStyle("TITLE", parent=ss["Title"], fontName="Helvetica-Bold",
                       fontSize=25, textColor=NAVY, leading=29, alignment=TA_CENTER)
SUB = ParagraphStyle("SUB", parent=BODY, fontSize=11.5, alignment=TA_CENTER,
                     textColor=GREY, leading=16)

story = []


def p(t, s=BODY):
    story.append(Paragraph(t, s))


def bullets(items, style=BULLET):
    for it in items:
        story.append(Paragraph(it, style, bulletText="\u2022"))
    story.append(Spacer(1, 4))


def sp(h=6):
    story.append(Spacer(1, h))


def formula(lines, title=None):
    """Monospace formula box."""
    inner = []
    if title:
        inner.append(Paragraph(title, ParagraphStyle(
            "ft", parent=SMALL, fontName="Helvetica-Bold", textColor=NAVY,
            fontSize=8.3, spaceAfter=3)))
    for ln in lines:
        inner.append(Paragraph(ln.replace(" ", "&nbsp;"), MONO))
    t = Table([[inner]], colWidths=[168 * mm])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), colors.HexColor("#f8fafc")),
        ("BOX", (0, 0), (-1, -1), 0.6, LINE),
        ("LINEBEFORE", (0, 0), (0, -1), 2.4, BLUE),
        ("LEFTPADDING", (0, 0), (-1, -1), 9),
        ("RIGHTPADDING", (0, 0), (-1, -1), 7),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    story.append(t)
    sp(7)


def table(rows, widths, header_bg=NAVY, align_right=(), font=8.3, hdr_color=colors.white):
    data = []
    for ri, row in enumerate(rows):
        out = []
        for ci, c in enumerate(row):
            if isinstance(c, Paragraph):
                out.append(c)
            else:
                st = CELLB if ri == 0 else CELL
                st = ParagraphStyle("x", parent=st, fontSize=font,
                                    textColor=hdr_color if ri == 0 else st.textColor)
                if ci in align_right:
                    st = ParagraphStyle("y", parent=st, alignment=2)
                out.append(Paragraph(str(c), st))
        data.append(out)
    t = Table(data, colWidths=widths, repeatRows=1)
    style = [
        ("BACKGROUND", (0, 0), (-1, 0), header_bg),
        ("VALIGN", (0, 0), (-1, -1), "TOP"),
        ("GRID", (0, 0), (-1, -1), 0.4, colors.HexColor("#dbe4ee")),
        ("BOX", (0, 0), (-1, -1), 0.7, LINE),
        ("LEFTPADDING", (0, 0), (-1, -1), 5),
        ("RIGHTPADDING", (0, 0), (-1, -1), 5),
        ("TOPPADDING", (0, 0), (-1, -1), 3.5),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 3.5),
    ]
    for i in range(1, len(data)):
        if i % 2 == 0:
            style.append(("BACKGROUND", (0, i), (-1, i), LIGHT))
    t.setStyle(TableStyle(style))
    story.append(t)
    sp(8)


def note(text, color=colors.HexColor("#fffbeb"), bar=colors.HexColor("#e6a817"), label="NOTE"):
    inner = [Paragraph("<b>%s</b>&nbsp;&nbsp;%s" % (label, text),
                       ParagraphStyle("nn", parent=BODY, fontSize=8.5, leading=12))]
    t = Table([[inner]], colWidths=[168 * mm])
    t.setStyle(TableStyle([
        ("BACKGROUND", (0, 0), (-1, -1), color),
        ("LINEBEFORE", (0, 0), (0, -1), 2.6, bar),
        ("BOX", (0, 0), (-1, -1), 0.5, colors.HexColor("#e5e7eb")),
        ("LEFTPADDING", (0, 0), (-1, -1), 9),
        ("RIGHTPADDING", (0, 0), (-1, -1), 8),
        ("TOPPADDING", (0, 0), (-1, -1), 6),
        ("BOTTOMPADDING", (0, 0), (-1, -1), 6),
    ]))
    story.append(t)
    sp(7)


# ══════════════════════════════════════════════════════════════════════════
# COVER
# ══════════════════════════════════════════════════════════════════════════
sp(52)
p("MagDyn HRMS", ParagraphStyle("c1", parent=SUB, fontSize=13,
                                fontName="Helvetica-Bold", textColor=BLUE))
sp(6)
p("Complete Salary Calculation", TITLE)
sp(8)
p("How a monthly salary slip is computed, end to end -<br/>"
  "every input, formula, rate base, deduction and guard.", SUB)
sp(26)

cover = Table([[Paragraph(
    "<b>Engine</b>&nbsp;&nbsp;includes/PayrollCalculator.php<br/>"
    "<b>Extras</b>&nbsp;&nbsp;includes/payroll_extras.php<br/>"
    "<b>Day rules</b>&nbsp;&nbsp;includes/helpers.php (attendance_classify)<br/>"
    "<b>Constants</b>&nbsp;&nbsp;config/app.php<br/>"
    "<b>Entry points</b>&nbsp;&nbsp;modules/payroll/calculate.php (preview), "
    "generate_slip.php (persist)",
    ParagraphStyle("cv", parent=BODY, fontSize=9.5, leading=16))]],
    colWidths=[132 * mm])
cover.setStyle(TableStyle([
    ("BACKGROUND", (0, 0), (-1, -1), LIGHT),
    ("BOX", (0, 0), (-1, -1), 0.8, LINE),
    ("LEFTPADDING", (0, 0), (-1, -1), 16),
    ("TOPPADDING", (0, 0), (-1, -1), 14),
    ("BOTTOMPADDING", (0, 0), (-1, -1), 14),
]))
story.append(cover)
sp(22)
p("All amounts in this document are Indian Rupees, written as <b>Rs.</b>", SUB)
story.append(PageBreak())

# ══════════════════════════════════════════════════════════════════════════
# 1. PIPELINE
# ══════════════════════════════════════════════════════════════════════════
p("1. The calculation pipeline", H1)
p("A slip is produced by one call to <b>PayrollCalculator::computePayroll()</b>, "
  "followed by <b>payroll_apply_extras()</b>. The order below is the order the code "
  "actually executes in - each step consumes the output of the ones above it.", BODY)

table([
    ["#", "Step", "What it produces"],
    ["0", "Resolve effective salary", "Fixed monthly CTC in force at month-end (point-in-time)"],
    ["1", "Build the month calendar", "Calendar days, working days, working-day dates"],
    ["2", "Read + classify attendance", "Full / half / short / absent days, late minutes, OT minutes"],
    ["3", "Credit paid leave", "Approved paid leave + classified paid leave + admin paid leave"],
    ["4", "Split CTC into allowances", "Basic, HRA, Conveyance, ... , Special Allowance residual"],
    ["5", "Prorate to days earned", "Every allowance x earn ratio (paid days / calendar days)"],
    ["6", "Add overtime", "OT hours x Basic hourly rate x 2"],
    ["7", "Gross earnings", "Sum of all allowance lines"],
    ["8", "Statutory deductions", "PF 12% of earned Basic (cap Rs. 1,800), ESI 0.75%"],
    ["9", "Discipline deductions", "Late-arrival half day (Basic rate), late penalty (2x)"],
    ["10", "Extras", "Benefits + bonuses (earn), cashless benefit + loan EMI (deduct)"],
    ["11", "Totals", "Gross, total deductions, Net Pay = Gross - Deductions (floored at 0)"],
], [10 * mm, 48 * mm, 110 * mm])

note("Steps 5 and 8 are linked: because allowances are prorated <i>before</i> PF is "
     "computed, PF is charged on <b>earned</b> Basic, not full-month Basic. Overtime and "
     "the late penalty deliberately use <b>full-month</b> Basic instead.")

# ══════════════════════════════════════════════════════════════════════════
# 2. CONSTANTS
# ══════════════════════════════════════════════════════════════════════════
p("2. Configuration constants and settings", H1)
p("2.1 Statutory constants - <font face='Courier'>config/app.php</font>", H2)
table([
    ["Constant", "Value", "Meaning"],
    ["PAYROLL_PF_EMPLOYEE", "0.12", "Employee PF = 12% of Basic"],
    ["PAYROLL_PF_EMPLOYER", "0.12", "Employer PF match (mirrored from the employee figure)"],
    ["PF cap basic (class const)", "15,000", "PF is capped at 12% of 15,000 = Rs. 1,800"],
    ["PAYROLL_ESI_EMPLOYEE", "0.0075", "Employee ESI = 0.75% of monthly CTC"],
    ["PAYROLL_ESI_EMPLOYER", "0.0075", "Employer ESI (set equal to the employee rate in this build)"],
    ["PAYROLL_ESI_WAGE_LIMIT", "21,000", "ESI applies only while CTC is strictly below this"],
    ["PAYROLL_WORKING_DAYS", "26", "Legacy flat figure - used only for a badge, not the maths"],
    ["BASIC_FALLBACK_RATIO", "0.40", "Basic = 40% of CTC when no 'Basic' component exists"],
], [52 * mm, 25 * mm, 91 * mm])

p("2.2 Timing settings (per shift, with global fallbacks)", H2)
p("Every timing threshold resolves in this order: the shift <b>stamped on the attendance "
  "row</b> at mark time, then the employee's current shift, then the legacy global setting. "
  "That is what lets a past month keep being calculated against the shift the employee "
  "actually worked, even after they move shifts.", BODY)
table([
    ["Setting", "Default", "Used for"],
    ["office_start_time", "09:00", "Late minutes are measured from here"],
    ["office_end_time", "18:00", "Left-early test for the short-hours rule"],
    ["daily_grace_minutes", "15", "Check-in after start + grace = Late"],
    ["monthly_grace_minutes", "90", "Late penalty triggers only past this monthly total"],
    ["half_day_cutoff", "start + 2h (11:00)", "Check-in at/after this = Half Day, no late"],
    ["ot_trigger_time", "20:30", "Check-out must reach this before any OT is credited"],
    ["ot_baseline_time", "18:15", "OT minutes are counted from here, not from the trigger"],
    ["lunch_start / lunch_end", "13:00 - 13:30", "Per-employee lunch batch; deducted from worked time"],
    ["tea1 / tea2", "11:00-11:15, 16:00-16:15", "Two global tea breaks; deducted from worked time"],
], [42 * mm, 34 * mm, 92 * mm])

note("A shift with <b>ot_enabled = 0</b> (a straight shift) earns zero overtime. This is "
     "absolute: even an admin's manual OT-hours override is forced back to 0, so payroll "
     "always agrees with what the attendance sheet recorded.", label="RULE")



# ══════════════════════════════════════════════════════════════════════════
# 3. STEP 0 - SALARY RESOLUTION
# ══════════════════════════════════════════════════════════════════════════
p("3. Step 0 - Which salary applies to this month", H1)
p("A slip for a past month must use the salary that was in force <b>then</b>, never today's "
  "post-increment salary. <font face='Courier'>getSalaryForMonth()</font> resolves it "
  "point-in-time against the last day of the payroll month:", BODY)
formula([
    "lastDay = last calendar day of the payroll month",
    "",
    "1. sRow = latest salary_structures row  with effective_from  <= lastDay",
    "   iRow = latest employee_increments row with effective_date <= lastDay",
    "   -  both present  : the LATER event wins; on a tie the increment wins",
    "   -  one present   : that one",
    "",
    "2. neither present (month predates every salary event):",
    "   salary = earliest increment's previous_salary   (original pre-increment pay)",
    "",
    "3. still nothing: salary = employees.fixed_salary, else 0",
], "Point-in-time salary resolution")
note("The <font face='Courier'>is_current</font> flag on salary_structures is deliberately "
     "<b>not</b> used here. It marks today's active salary, which is the wrong answer for a "
     "historical month.")
p("If the resolved fixed salary is 0 or less, no slip can be generated - the employee is "
  "reported as <i>No salary structure</i> on the calculation register.", BODY)

# ══════════════════════════════════════════════════════════════════════════
# 4. STEP 1 - CALENDAR
# ══════════════════════════════════════════════════════════════════════════
p("4. Step 1 - Working days for the month", H1)
p("Working days are computed from the real calendar, not from a flat constant, so the "
  "figure genuinely differs between February and August.", BODY)
formula([
    "for each day d in the month:",
    "    if d is Sunday                      -> skip   (weekly off)",
    "    if d is Saturday:",
    "        satCount = satCount + 1",
    "        if satCount is 1 or 3           -> skip   (1st & 3rd Saturday off)",
    "        else                            -> WORKING (2nd, 4th, 5th Saturday)",
    "    if d is a declared holiday          -> skip",
    "    otherwise                           -> WORKING",
], "Working-day rule")
bullets([
    "<b>Calendar days</b> (28-31) is the denominator for every per-day rate and for the "
    "earned-salary proration - <i>not</i> working days.",
    "<b>Working days</b> is the denominator for the absence test only: it decides how many "
    "days the employee was expected to be present.",
    "The Saturday test runs <i>before</i> the holiday test, so a holiday falling on an "
    "already-off 1st/3rd Saturday is never double-counted.",
])
note("The blue badge on the Salary Calculation screen shows "
     "<font face='Courier'>PAYROLL_WORKING_DAYS (26) - holidays</font>, which is the legacy "
     "flat figure. The engine itself uses the real calendar rule above, so the badge and the "
     "per-employee maths can disagree by a day or two. The badge is display only - it never "
     "enters a calculation.", color=colors.HexColor("#fef2f2"), bar=RED, label="CAVEAT")



# ══════════════════════════════════════════════════════════════════════════
# 5. STEP 2 - DAY CLASSIFICATION
# ══════════════════════════════════════════════════════════════════════════
p("5. Step 2 - How each worked day is classified", H1)
p("Every punched day (status On Time / Late / Half Day, with both a check-in and a "
  "check-out) is re-classified from the <b>actual times</b> - the stored status label is not "
  "trusted. First the net worked time is derived:", BODY)
formula([
    "presence   = check_out - check_in                      (in minutes)",
    "breaks     = the parts of lunch + tea break 1 + tea break 2",
    "             that fall INSIDE [check_in, check_out]",
    "net worked = presence - breaks",
    "",
    "A full day = 480 net minutes (8 hours).   A half day = 240 minutes (4 hours).",
], "Net worked minutes")
p("Only the <i>overlap</i> of a break window with the presence window is subtracted, so an "
  "employee who leaves before the 16:00 tea break is never charged for it. A shift "
  "configured with no breaks and no lunch subtracts nothing at all.", BODY)

p("5.1 The classification ladder (first match wins)", H2)
table([
    ["#", "Condition", "Status", "Deducted (ded_days)", "Late applies?"],
    ["1", "net &lt; 4h", "short", "(8h - net) / 8h", "No"],
    ["2", "check-in at/after the half-day cutoff (~11:00), net &gt;= 4h",
     "half<br/><font size=7>reason: late_arrival</font>", "max(0.5, (8h - net)/8h)", "No"],
    ["3", "net exactly 4h", "half<br/><font size=7>reason: half_worked</font>",
     "(8h - net) / 8h  = 0.5", "No"],
    ["4", "net &gt;= 8h, <b>or</b> stayed until/after office end", "full", "0", "Yes"],
    ["5", "4h &lt; net &lt; 8h and left before office end", "present", "(8h - net) / 8h", "Yes"],
], [8 * mm, 62 * mm, 32 * mm, 36 * mm, 20 * mm], font=7.9)

bullets([
    "<b>Why rule 4 charges nothing:</b> if the employee stayed to the end of the shift, any "
    "shortfall against 8h is caused by a late check-in - which the late penalty already "
    "charges. Deducting short hours as well would punish the same lateness twice.",
    "<b>Why rules 1-3 set late = false:</b> the day has already lost half a day or more, so "
    "stacking the late penalty on top would be a second charge for the same tardiness.",
    "A day is counted as <b>late</b> only when check-in &gt; shift start + daily grace, and "
    "the minutes banked into the monthly pool are the full <i>(check-in - shift start)</i>, "
    "grace included.",
])

p("5.2 Aggregates handed to payroll", H2)
table([
    ["Field", "Meaning"],
    ["present", "full + present + half + OD + Comp Off - every day that is 'not absent'"],
    ["half_ded_days", "Total half-day equivalent to charge (sum of ded_days for 'half' days)"],
    ["late_half_ded_days", "The part of the above caused by a late arrival (rule 2) - priced off Basic"],
    ["short_ded_days", "Total shortfall in days for 'short' and 'present' days"],
    ["late_minutes", "Monthly late pool (only from days where late = true)"],
    ["ot_hours", "Sum of OT minutes / 60 across On Time, Late, Half Day, OD"],
    ["leave / paid_leave", "Attendance rows with status Holiday - counted as paid"],
    ["absent", "Attendance rows with status Absent"],
], [40 * mm, 128 * mm])

p("5.3 Overtime minutes", H2)
formula([
    "OT is credited for a day only when BOTH hold:",
    "   the shift has ot_enabled = 1 AND has both an OT trigger and an OT baseline",
    "   check_out >= ot_trigger_time            (e.g. 20:30)",
    "",
    "OT minutes for the day = check_out - ot_baseline_time     (e.g. from 18:15)",
], "OT minute rule")
p("So the trigger is a <b>gate</b> and the baseline is the <b>meter</b>: an employee who "
  "leaves at 20:29 earns nothing, while one who leaves at 20:31 is paid from 18:15 - "
  "2 hours 16 minutes.", BODY)



# ══════════════════════════════════════════════════════════════════════════
# 6. STEP 3 - PAID LEAVE AND ABSENCE
# ══════════════════════════════════════════════════════════════════════════
p("6. Step 3 - Paid leave, and what counts as absent", H1)
p("Three independent sources can convert a day into <b>paid leave</b>, so it is excluded "
  "from Loss of Pay:", BODY)
table([
    ["Source", "Condition", "Applied"],
    ["Approved leave request",
     "leave_requests.status = approved <b>and</b> leave_types.is_paid = 1, "
     "intersected with the month's working days",
     "Before the absence count"],
    ["Classified absence",
     "An Absent attendance row marked leave_classification = 'paid' on the Attendance Report, "
     "restricted to working days",
     "Before the absence count"],
    ["Admin entry on the form",
     "The <i>Paid leaves</i> field on Generate Slip - converts up to that many <b>absent</b> "
     "days into paid leave",
     "After the absence count"],
], [37 * mm, 96 * mm, 35 * mm])
p("Unpaid leave types (is_paid = 0, e.g. Loss of Pay) are deliberately excluded from the "
  "first source and therefore remain LOP. Each working day is credited once even if several "
  "approved requests overlap it.", BODY)
formula([
    "absentDays = working days - present days - paid leave days      (floored at 0)",
    "",
    "manualPaid = min( admin paid-leave days, floor(absentDays) )",
    "absentDays = absentDays - manualPaid",
    "",
    "lopDays    = absentDays          <- the final Loss of Pay figure",
], "Absence")
p("Example: 4 absent days with 2 paid leaves entered by the admin leaves 2 LOP days.", BODY)

# ══════════════════════════════════════════════════════════════════════════
# 7. STEP 4 - EARNINGS SPLIT
# ══════════════════════════════════════════════════════════════════════════
p("7. Step 4 - Splitting the CTC into allowances", H1)
p("Rows in <font face='Courier'>salary_components</font> with type = allowance are applied "
  "to the monthly CTC in <font face='Courier'>sort_order</font>. A component is either a "
  "<b>percentage</b> of CTC or a <b>flat</b> amount. Components literally named 'PF' or "
  "'ESI' are skipped here - those are deductions, computed authoritatively later.", BODY)
table([
    ["Seeded component", "Type", "Value", "On Rs. 30,000 CTC"],
    ["Basic", "percentage", "55.00 %", "16,500.00"],
    ["HRA", "percentage", "25.00 %", "7,500.00"],
    ["Conveyance allowance", "percentage", "5.00 %", "1,500.00"],
    ["Vehicle allowance", "percentage", "5.00 %", "1,500.00"],
    ["Product Incentive", "percentage", "10.00 %", "3,000.00"],
    ["<b>Total</b>", "", "<b>100.00 %</b>", "<b>30,000.00</b>"],
], [55 * mm, 28 * mm, 30 * mm, 55 * mm], align_right=(3,))

p("7.1 Basic resolution and the residual", H2)
formula([
    "Basic = the allowance whose NAME CONTAINS 'basic'",
    "        (exact match on 'Basic Salary' preferred; else the first fuzzy match)",
    "",
    "if NO such component exists:",
    "        Basic = 40% of CTC, inserted as a new 'Basic Salary' line",
    "",
    "Special Allowance = CTC - sum(all allowance lines)      (added only when > 0)",
    "Variable Pay      = added as its own line when > 0",
], "Basic + residual")
note("Basic is the single most load-bearing number on the slip: it drives PF, the overtime "
     "rate and both late-related deductions. If the Basic component is ever deleted, the "
     "engine silently falls back to 40% of CTC and all three of those figures change.",
     label="IMPORTANT")



# ══════════════════════════════════════════════════════════════════════════
# 8. STEP 5 - PRORATION
# ══════════════════════════════════════════════════════════════════════════
p("8. Step 5 - Earned-salary proration (the core idea)", H1)
p("This build pays for <b>days earned</b> rather than paying the full CTC and then "
  "subtracting an 'Absent Deduction'. Both models arrive at the same take-home, but this one "
  "states it honestly: every line on the slip shows what was actually earned.", BODY)
formula([
    "unpaidDays = lopDays",
    "           + (half_ded_days - late_half_ded_days)      <- genuine half days only",
    "           + short_ded_days",
    "",
    "paidDays   = calendarDays - unpaidDays",
    "earnRatio  = paidDays / calendarDays",
    "",
    "if earnRatio < 1:",
    "     every allowance line = round(line x earnRatio, 2)",
    "     Basic                = round(Basic x earnRatio, 2)   -> PF follows earned wages",
], "Proration")
bullets([
    "Weekly offs and holidays are <b>not</b> subtracted. An employee who works every working "
    "day is paid 31/31 and receives the full CTC.",
    "Because absence is now priced into the earnings, the Absent / Half Day / Short Hours "
    "<b>deduction lines no longer appear on the slip</b>. Keeping them would charge the same "
    "absence twice. The amounts are still computed and stored in the attendance summary so "
    "the reports can show what each shortfall was worth.",
    "Half days caused by a <b>late arrival</b> are excluded from unpaidDays on purpose - "
    "they are charged separately against Basic (section 10.1) rather than being prorated out "
    "of every component at the CTC rate.",
])

# ══════════════════════════════════════════════════════════════════════════
# 9. RATE BASES
# ══════════════════════════════════════════════════════════════════════════
p("9. The two rate bases", H1)
p("This is the detail most often misread. The system prices money <i>out</i> and money "
  "<i>in</i> from two different wage bases.", BODY)
table([
    ["Rate", "Formula", "Charged / paid on"],
    ["perDay<br/><font size=7>(CTC base)</font>", "CTC / calendarDays",
     "Absent, half-day and short-hours amounts - i.e. everything folded into the proration"],
    ["perHour", "perDay / 8", "Reporting figure on the attendance summary"],
    ["basicPerDay<br/><font size=7>(Basic base)</font>",
     "Basic<sub>full month</sub> / calendarDays",
     "The late-arrival half day"],
    ["basicPerHour", "basicPerDay / 8", "Overtime pay and the monthly late penalty"],
], [34 * mm, 44 * mm, 90 * mm], font=8.2)
note("<b>Basic</b><sub>full month</sub> is captured <i>before</i> proration. Overtime and "
     "the late penalty are therefore priced off the standard, unprorated wage rate - a late "
     "hour is charged against exactly the same base that an overtime hour is paid from.",
     label="SYMMETRY")

# ══════════════════════════════════════════════════════════════════════════
# 10. OT AND DEDUCTIONS
# ══════════════════════════════════════════════════════════════════════════
p("10. Step 6-9 - Overtime and every deduction", H1)
p("10.1 Overtime pay", H2)
formula([
    "otHours  = manual override if the admin entered one, else the attendance total",
    "           (forced to 0 when the shift has OT switched off)",
    "",
    "otAmount = otHours x basicPerHour x 2",
    "",
    "Slip line:  \"Overtime (6.5 hrs)\"          <- an EARNING",
], "Overtime")

p("10.2 Provident Fund", H2)
formula([
    "only when employees.pf_enabled = 1",
    "",
    "pfEmployee = min( earnedBasic x 12% ,  15,000 x 12% )",
    "           = min( earnedBasic x 12% ,  1,800.00 )",
    "pfEmployer = pfEmployee",
    "",
    "Slip line:  \"Provident Fund (PF)\"         <- a DEDUCTION",
], "PF")
p("The employer share is an employer cost - it is reported separately and is never taken "
  "out of the employee's net pay.", BODY)

p("10.3 ESI", H2)
formula([
    "only when employees.pf_enabled = 1  AND  CTC < 21,000",
    "",
    "esiEmployee = CTC x 0.75%",
    "esiEmployer = CTC x 0.75%",
    "",
    "Slip line:  \"ESI (Employee)\"              <- a DEDUCTION",
], "ESI")
note("ESI is gated by the <i>same</i> pf_enabled toggle as PF. Switching PF off for an "
     "employee therefore stops both statutory deductions. Note also that ESI is charged on "
     "the full CTC, not on the prorated earned amount.")
note("The badge on the Salary Calculation screen reads <i>'ESI: 0.75% + 3.25%'</i>, but "
     "<font face='Courier'>PAYROLL_ESI_EMPLOYER</font> is set to 0.0075 in config/app.php - "
     "so this build actually applies 0.75% + 0.75%. The badge text is stale; the constant is "
     "what the engine uses.", color=colors.HexColor("#fef2f2"), bar=RED, label="CAVEAT")



p("10.4 Half day caused by a late arrival", H2)
formula([
    "lateHalfDeduction = late_half_ded_days x basicPerDay",
    "",
    "Slip line:  \"Half Day - Late Arrival (1 day)\"        <- a DEDUCTION",
], "Late-arrival half day")
p("This is the only place such a day is charged. It was held out of the proration in step 5 "
  "precisely so it could be priced against Basic here instead of against the full CTC.", BODY)

p("10.5 Monthly late penalty", H2)
formula([
    "if totalLateMinutes > monthlyGrace   (default 90 minutes):",
    "",
    "     deductableMinutes = totalLateMinutes x 2      <- the FULL total, doubled",
    "     lateDeduction     = deductableMinutes x (basicPerHour / 60)",
    "",
    "Slip line:  \"Late Deduction (100 min, 2x rate)\"      <- a DEDUCTION",
], "Late penalty")
note("Once the grace is exceeded the <b>entire</b> monthly late total is charged at double "
     "rate - not merely the minutes beyond the grace. 91 late minutes is charged as 182 "
     "minutes. Below the grace, nothing at all is charged.",
     label="CLIFF EDGE")

p("10.6 Component deductions", H2)
p("Any <font face='Courier'>salary_components</font> row with type = deduction is applied as "
  "a percentage of CTC or a flat amount - except rows literally named 'PF' or 'ESI', which "
  "are skipped so the authoritative calculations in 10.2 and 10.3 win.", BODY)

# ══════════════════════════════════════════════════════════════════════════
# 11. EXTRAS
# ══════════════════════════════════════════════════════════════════════════
p("11. Step 10 - Benefits, bonuses and loans", H1)
p("<font face='Courier'>payroll_apply_extras()</font> appends extra line items and re-sums "
  "the two totals. It changes <b>no</b> core formula - gross is still the sum of allowances "
  "and net is still gross minus deductions.", BODY)

p("11.1 Benefit funds - an EARNING", H2)
table([
    ["Rule", "Behaviour"],
    ["Eligibility", "status = active, and the benefit is live in this payroll month"],
    ["End-date guard", "Once end_date has passed, the benefit never appears again - in both "
     "recurring and legacy modes"],
    ["Recurring window", "start_date must be on or before month-end; frequency then decides: "
     "quarterly / half-yearly match the period, annual matches the month, monthly always matches"],
    ["Legacy mode", "No start_date: matches only when effective_month is exactly this month"],
    ["Occurrences", "weekly = active days / 7, fortnightly = active days / 14 (min 1); "
     "everything else pays once"],
    ["Amount", "amount x occurrences, labelled <font face='Courier'>[BENEFIT]</font>"],
], [33 * mm, 135 * mm])
note("<b>Cashless benefits net to zero.</b> A benefit with payment_mode = 'cashless' is paid "
     "directly to the provider (insurer, education fund), not handed to the employee. It is "
     "therefore added as an earning <i>and</i> subtracted as a matching deduction "
     "'&lt;name&gt; (Cashless - paid to provider)'. Take-home is unchanged; the slip shows "
     "the full value earned. A <b>cash</b> benefit has no matching deduction and does raise "
     "take-home.")

p("11.2 Bonuses and incentives - an EARNING", H2)
p("Rows in <font face='Courier'>employee_bonuses</font> with status = approved and "
  "payroll_month / payroll_year matching this run, labelled "
  "<font face='Courier'>[BONUS]</font>. Types: Monthly Bonus, Performance Incentive, "
  "Festival Bonus, Overtime Incentive, One-time Reward.", BODY)

p("11.3 Loans and advances - a DEDUCTION", H2)
formula([
    "for each active loan with monthly_deduction > 0:",
    "",
    "  skip if date_given is after this month's end       (not yet disbursed)",
    "",
    "  totalDue       = principal + interest",
    "  returnedBefore = sum of what PRIOR generated slips actually deducted",
    "  remaining      = totalDue - returnedBefore",
    "  skip if remaining <= 0                             (already cleared)",
    "",
    "  if this is the final instalment OR remaining <= EMI:",
    "        deduct = remaining          <- clears the exact balance",
    "  else: deduct = min(EMI, remaining)",
], "Loan EMI")
p("Settling the final instalment against the exact remaining balance is what stops rounding "
  "drift from leaving a stray few rupees pending forever.", BODY)



# ══════════════════════════════════════════════════════════════════════════
# 12. TOTALS
# ══════════════════════════════════════════════════════════════════════════
p("12. Step 11 - Final totals", H1)
formula([
    "grossEarnings   = sum of every allowance line",
    "                  (components + special allowance + variable pay + overtime",
    "                   + benefits + bonuses)",
    "",
    "totalDeductions = sum of every deduction line",
    "                  (component deductions + PF + ESI + late-arrival half day",
    "                   + late penalty + cashless benefits + loan EMIs)",
    "",
    "netPay          = max( 0 , grossEarnings - totalDeductions )",
    "",
    "Employer cost   = grossEarnings + pfEmployer + esiEmployer",
], "Totals")
note("Net pay is floored at zero. If deductions were ever to exceed gross - a large loan EMI "
     "in a heavy-LOP month - the slip shows 0 rather than a negative figure, and the shortfall "
     "is not automatically carried forward.")

# ══════════════════════════════════════════════════════════════════════════
# 13. WORKED EXAMPLE
# ══════════════════════════════════════════════════════════════════════════
p("13. Worked example, end to end", H1)
p("<b>Scenario</b> - August 2026 (31 calendar days). Monthly CTC Rs. 30,000. PF enabled. "
  "Standard shift 09:00-18:00, OT enabled, seeded salary components.", BODY)

p("13.1 Calendar", H2)
table([
    ["Item", "Value", "Working"],
    ["Calendar days", "31", "August"],
    ["Sundays", "5", "2, 9, 16, 23, 30 - all off"],
    ["Saturdays off", "2", "1st (Aug 1) and 3rd (Aug 15); the 8th, 22nd, 29th are working"],
    ["Declared holidays", "1", "Aug 15 - already an off Saturday, so no further reduction"],
    ["<b>Working days</b>", "<b>24</b>", "31 - 5 - 2"],
], [38 * mm, 20 * mm, 110 * mm])

p("13.2 Attendance for the month", H2)
table([
    ["Days", "What happened", "Classified", "ded_days"],
    ["20", "Worked a full shift", "full", "0"],
    ["1", "Net 6h, left before 18:00", "present", "(8-6)/8 = 0.25"],
    ["1", "Checked in 11:30, worked 5h", "half (late_arrival)", "max(0.5, 0.375) = 0.50"],
    ["1", "Approved paid Casual Leave", "paid leave", "0"],
    ["1", "Absent, unexplained", "absent", "1.00 (LOP)"],
    ["<b>24</b>", "<b>Total working days</b>", "", ""],
], [15 * mm, 62 * mm, 46 * mm, 45 * mm], font=8.2)
p("Also recorded: late arrivals on 3 of the full days totalling <b>100 minutes</b>, and "
  "<b>6.5 hours</b> of overtime.", BODY)

p("13.3 Days earned", H2)
formula([
    "present    = 20 full + 1 present + 1 half = 22",
    "paid leave = 1        (approved casual leave)",
    "absentDays = 24 - 22 - 1 = 1        -> lopDays = 1",
    "",
    "unpaidDays = 1.00 (LOP)",
    "           + (0.50 half - 0.50 late-arrival half)   = 0.00",
    "           + 0.25 (short)",
    "           = 1.25",
    "",
    "paidDays   = 31 - 1.25 = 29.75",
    "earnRatio  = 29.75 / 31 = 0.959677",
], "Proration for the example")



p("13.4 Rates", H2)
table([
    ["Rate", "Working", "Value (Rs.)"],
    ["Basic (full month)", "30,000 x 55%", "16,500.0000"],
    ["perDay (CTC base)", "30,000 / 31", "967.7419"],
    ["perHour", "967.7419 / 8", "120.9677"],
    ["basicPerDay", "16,500 / 31", "532.2581"],
    ["basicPerHour", "532.2581 / 8", "66.5323"],
], [45 * mm, 55 * mm, 68 * mm], align_right=(2,))

p("13.5 Earnings", H2)
table([
    ["Line", "Full month", "x 0.959677", "Earned (Rs.)"],
    ["Basic (55%)", "16,500.00", "", "15,834.68"],
    ["HRA (25%)", "7,500.00", "", "7,197.58"],
    ["Conveyance allowance (5%)", "1,500.00", "", "1,439.52"],
    ["Vehicle allowance (5%)", "1,500.00", "", "1,439.52"],
    ["Product Incentive (10%)", "3,000.00", "", "2,879.03"],
    ["<i>Special Allowance</i>", "<i>0.00</i>", "", "<i>not added - components total 100%</i>"],
    ["<b>Prorated CTC</b>", "<b>30,000.00</b>", "", "<b>28,790.33</b>"],
    ["Overtime (6.5 hrs)", "6.5 x 66.5323 x 2", "not prorated", "864.92"],
    ["[BENEFIT] Health Insurance <font size=7>(cashless)</font>", "1,000.00", "not prorated", "1,000.00"],
    ["[BONUS] Performance Incentive", "2,000.00", "not prorated", "2,000.00"],
    ["<b>GROSS EARNINGS</b>", "", "", "<b>32,655.25</b>"],
], [60 * mm, 32 * mm, 30 * mm, 46 * mm], align_right=(1, 3), header_bg=GREEN, font=8.1)

p("13.6 Deductions", H2)
table([
    ["Line", "Working", "Amount (Rs.)"],
    ["Provident Fund (PF)", "min(15,834.68 x 12% = 1,900.16 , cap 1,800.00)", "1,800.00"],
    ["ESI (Employee)", "not applicable - CTC 30,000 is not below 21,000", "0.00"],
    ["Half Day - Late Arrival (1 day)", "0.50 x 532.2581", "266.13"],
    ["Late Deduction (100 min, 2x rate)", "100 x 2 = 200 min x (66.5323 / 60)", "221.77"],
    ["Health Insurance (Cashless - paid to provider)", "mirrors the benefit earning", "1,000.00"],
    ["Advance Deduction #1", "monthly EMI on an active advance", "2,500.00"],
    ["<b>TOTAL DEDUCTIONS</b>", "", "<b>5,787.90</b>"],
], [58 * mm, 72 * mm, 38 * mm], align_right=(2,), header_bg=RED, font=8.1)

p("13.7 Net pay", H2)
final = Table([
    [Paragraph("<b>Gross earnings</b>", ParagraphStyle("f1", parent=BODY, fontSize=10.5, textColor=colors.white)),
     Paragraph("<b>32,655.25</b>", ParagraphStyle("f2", parent=BODY, fontSize=10.5, alignment=2, textColor=colors.white))],
    [Paragraph("<b>Less: total deductions</b>", ParagraphStyle("f3", parent=BODY, fontSize=10.5, textColor=colors.white)),
     Paragraph("<b>- 5,787.90</b>", ParagraphStyle("f4", parent=BODY, fontSize=10.5, alignment=2, textColor=colors.white))],
    [Paragraph("<b>NET PAY</b>", ParagraphStyle("f5", parent=BODY, fontSize=13.5, textColor=colors.white)),
     Paragraph("<b>Rs. 26,867.35</b>", ParagraphStyle("f6", parent=BODY, fontSize=13.5, alignment=2, textColor=colors.white))],
], colWidths=[110 * mm, 58 * mm])
final.setStyle(TableStyle([
    ("BACKGROUND", (0, 0), (-1, 1), colors.HexColor("#334155")),
    ("BACKGROUND", (0, 2), (-1, 2), NAVY),
    ("LINEABOVE", (0, 2), (-1, 2), 1.2, colors.white),
    ("BOX", (0, 0), (-1, -1), 0.8, NAVY),
    ("LEFTPADDING", (0, 0), (-1, -1), 12),
    ("RIGHTPADDING", (0, 0), (-1, -1), 12),
    ("TOPPADDING", (0, 0), (-1, -1), 7),
    ("BOTTOMPADDING", (0, 0), (-1, -1), 7),
]))
story.append(final)
sp(9)
p("<b>Employer cost:</b> gross 32,655.25 + employer PF 1,800.00 + employer ESI 0.00 = "
  "<b>Rs. 34,455.25</b>.", BODY)
p("Small rounding differences (a rupee or less) can appear because each allowance line is "
  "rounded to 2 decimals individually before being summed.", SMALL)



# ══════════════════════════════════════════════════════════════════════════
# 14. VARIANT
# ══════════════════════════════════════════════════════════════════════════
p("14. Variant - a salary where ESI applies and PF is not capped", H1)
p("Same month, CTC <b>Rs. 18,000</b>, perfect attendance (earnRatio = 1.0), no overtime, "
  "no extras, PF enabled.", BODY)
table([
    ["Line", "Working", "Amount (Rs.)"],
    ["Basic (55%)", "18,000 x 55%", "9,900.00"],
    ["HRA (25%)", "18,000 x 25%", "4,500.00"],
    ["Conveyance allowance (5%)", "18,000 x 5%", "900.00"],
    ["Vehicle allowance (5%)", "18,000 x 5%", "900.00"],
    ["Product Incentive (10%)", "18,000 x 10%", "1,800.00"],
    ["<b>Gross earnings</b>", "", "<b>18,000.00</b>"],
    ["Provident Fund (PF)", "min(9,900 x 12% = 1,188.00 , cap 1,800.00) - <b>under the cap</b>", "1,188.00"],
    ["ESI (Employee)", "18,000 &lt; 21,000, so 18,000 x 0.75%", "135.00"],
    ["<b>Total deductions</b>", "", "<b>1,323.00</b>"],
    ["<b>NET PAY</b>", "", "<b>16,677.00</b>"],
], [52 * mm, 78 * mm, 38 * mm], align_right=(2,), font=8.2)
p("Employer side: PF 1,188.00 + ESI 135.00 = Rs. 1,323.00, giving an employer cost of "
  "Rs. 19,323.00. The moment this employee's CTC reaches Rs. 21,000, ESI stops entirely - "
  "there is no taper.", BODY)

# ══════════════════════════════════════════════════════════════════════════
# 15. GUARDS
# ══════════════════════════════════════════════════════════════════════════
p("15. Guards, edge cases and stored data", H1)
p("15.1 Guards enforced before a slip can be generated", H2)
table([
    ["Guard", "Behaviour"],
    ["Future month", "Blocked. Payroll runs only for the current or a past month - otherwise "
     "a loan EMI would be deducted for a month that has not happened. The Generate buttons "
     "are disabled on the register too."],
    ["Before joining date", "The employee is listed but skipped - no calculation, no slip."],
    ["No salary structure", "Listed as 'No Salary' and skipped."],
    ["Inactive employee", "Only status = Active employees are processed."],
    ["Duplicate slip", "One slip per employee per payroll_month. A second attempt prompts to "
     "regenerate, which updates the existing row in place."],
    ["Self-scoped user", "An employee-scoped login sees only their own row."],
    ["CSRF", "Generation is a POST with CSRF verification."],
], [36 * mm, 132 * mm])

p("15.2 What is frozen onto the slip", H2)
p("Generation persists the computed figures so a slip never changes retroactively when "
  "master data is edited afterwards:", BODY)
bullets([
    "<b>allowances</b> and <b>deductions_json</b> - the full line-item breakdown, in the "
    "component sort order that applied at generation time.",
    "<b>attendance_summary</b> (JSON) - working days, present, half, paid leave, absent, "
    "late minutes and grace, deductable late minutes, OT hours and rate, paid/unpaid days, "
    "earn ratio, full-month Basic, per-day and per-hour rates, calendar days, CTC.",
    "<b>shift_name</b> - read from the shift stamped on each attendance row, so a slip "
    "regenerated after the employee changes shift still names the shift they actually worked.",
    "Flat columns for reporting: basic, hra, conveyance, medical, special_allow, "
    "gross_earnings, pf_employee, pf_employer, esi_employee, esi_employer, total_deductions, "
    "net_pay, working_days, present_days, lop_days.",
    "Generation also writes back per-day <b>worked_hours</b> and <b>deduction_amount</b> onto "
    "the attendance rows themselves, as an audit trail.",
])

p("15.3 Tables involved", H2)
table([
    ["Table", "Role"],
    ["employees", "Status, joining date, pf_enabled toggle, shift, lunch batch, fallback fixed_salary"],
    ["salary_structures", "CTC history (effective_from); is_current marks today's salary only"],
    ["employee_increments", "Increment audit trail - new_salary, previous_salary, effective_date"],
    ["salary_components", "The earnings/deductions breakup and its sort order"],
    ["attendance", "Per-day status, in/out times, stamped shift_id, leave_classification"],
    ["shifts", "Start/end, grace, half-day cutoff, OT enable + trigger + baseline, breaks"],
    ["holidays", "Declared holidays excluded from working days"],
    ["leave_requests + leave_types", "Approved paid leave (is_paid = 1)"],
    ["employee_benefits", "Benefit funds - frequency, dates, cash / cashless"],
    ["employee_bonuses", "Approved bonuses for a specific payroll month"],
    ["employee_loans", "Active loans and advances - EMI, tenure, interest"],
    ["salary_slips", "The generated slip and its frozen JSON breakdown"],
], [46 * mm, 122 * mm])

p("15.4 Summary of every formula on one page", H2)
formula([
    "workingDays    = calendar days - Sundays - 1st & 3rd Saturdays - holidays",
    "netWorked      = (out - in) - overlapping break windows",
    "unpaidDays     = LOP + genuine half days + short-hour shortfall",
    "earnRatio      = (calendarDays - unpaidDays) / calendarDays",
    "",
    "allowance_i    = (CTC x pct_i  or  flat_i) x earnRatio",
    "specialAllow   = CTC - sum(allowances)                  (if > 0)",
    "basicFullMonth = the 'basic' component at 100%, else CTC x 40%",
    "",
    "perDay         = CTC / calendarDays",
    "basicPerDay    = basicFullMonth / calendarDays",
    "basicPerHour   = basicPerDay / 8",
    "",
    "otAmount       = otHours x basicPerHour x 2",
    "pfEmployee     = min(earnedBasic x 12%, 1800)           (pf_enabled only)",
    "esiEmployee    = CTC x 0.75%                            (pf_enabled, CTC < 21000)",
    "lateHalfDed    = lateHalfDays x basicPerDay",
    "lateDed        = (totalLateMins x 2) x basicPerHour / 60  (only past 90 min)",
    "",
    "GROSS          = sum(allowances) + OT + benefits + bonuses",
    "DEDUCTIONS     = components + PF + ESI + lateHalfDed + lateDed",
    "                 + cashless benefits + loan EMIs",
    "NET PAY        = max(0, GROSS - DEDUCTIONS)",
], "Every formula")

# ══════════════════════════════════════════════════════════════════════════
# FOOTER / HEADER
# ══════════════════════════════════════════════════════════════════════════
def decorate(canv, doc):
    canv.saveState()
    w, h = A4
    canv.setStrokeColor(LINE)
    canv.setLineWidth(0.5)
    if doc.page > 1:
        canv.line(21 * mm, h - 15 * mm, w - 21 * mm, h - 15 * mm)
        canv.setFont("Helvetica", 7.5)
        canv.setFillColor(GREY)
        canv.drawString(21 * mm, h - 13.2 * mm, "MagDyn HRMS  |  Complete Salary Calculation")
        canv.drawRightString(w - 21 * mm, h - 13.2 * mm, "PayrollCalculator::computePayroll()")
    canv.line(21 * mm, 15 * mm, w - 21 * mm, 15 * mm)
    canv.setFont("Helvetica", 7.5)
    canv.setFillColor(GREY)
    canv.drawString(21 * mm, 11 * mm, "Generated from the HRMS source - August 2026")
    canv.drawRightString(w - 21 * mm, 11 * mm, "Page %d" % doc.page)
    canv.restoreState()


doc = SimpleDocTemplate(
    OUT, pagesize=A4,
    leftMargin=21 * mm, rightMargin=21 * mm,
    topMargin=20 * mm, bottomMargin=20 * mm,
    title="MagDyn HRMS - Complete Salary Calculation",
    author="MagDyn HRMS",
    subject="Payroll calculation reference",
)
doc.build(story, onFirstPage=decorate, onLaterPages=decorate)
print("OK ->", OUT)
