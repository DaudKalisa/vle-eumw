# Finance Officer's Guide - Rate Revision Feature

## Quick Start

### What Is This Feature?
You can now **revise hourly rates and airtime rates** for lecturer finance requests before approving and processing payments.

### Who Can Use It?
- **Finance Officers** (and Admin users)
- Available for **pending** and **approved** requests
- Cannot revise rates for already **paid** requests

---

## Step-by-Step Instructions

### 1. Open Finance Dashboard
- Log in as Finance Officer
- Go to **Finance → Lecturer Finance Requests** menu

### 2. Find the Request
- Locate the lecturer's finance request in the table
- Requests show: Date, Lecturer Name, Amount, Approval Status

### 3. Click "Edit Rates" Button
- Look in the **Action** column (rightmost)
- Click the **"✏️ Edit Rates"** button (yellow outline button)
- A popup dialog will appear

### 4. Enter Revised Rates
The popup has three fields:

#### Hourly Rate (Optional)
- Enter the new hourly rate in MKW
- Example: `9500` or `10000`
- If you change this, the **total amount** will automatically update
- Formula: `Total Amount = Total Hours × Hourly Rate`
- Leave blank if you don't want to change it

#### Airtime Rate (Optional)
- Enter the airtime/bundle allowance in MKW
- Example: `15000` or `20000`
- Leave blank if you don't want to change it

#### Reason for Revision
- Explain **why** you're revising the rates
- Examples:
  - "Standard rate adjustment per policy"
  - "Adjustment for senior lecturer status"
  - "Correction of previous calculation error"

### 5. Save the Changes
- Click **"Save Revised Rates"** button
- Wait for success confirmation
- The request will update with new amounts (if hourly rate was changed)

---

## Important Rules

✓ **What You Can Do:**
- Revise hourly rate
- Revise airtime rate
- Add both rates before payment
- Track all revisions (recorded in database)

✗ **What You CANNOT Do:**
- Revise rates on **paid** requests (already processed)
- Revise without providing a reason
- Revise after approval without recording reason

---

## Example Scenarios

### Scenario 1: Rate Correction
```
Lecturer: John Smith
Original Amount: MKW 85,000 (6,500/hr × 13 hrs)
Need to revise to: 9,500/hr standard rate
New Amount: MKW 123,500 (9,500/hr × 13 hrs)

Action:
1. Click "Edit Rates"
2. Enter 9500 in Hourly Rate field
3. Enter "Updated to standard hourly rate of K9,500"
4. Click Save
```

### Scenario 2: Airtime Adjustment
```
Lecturer: Jane Doe
Current Amount: MKW 104,000
Needs: Additional airtime allowance

Action:
1. Click "Edit Rates"
2. Leave Hourly Rate blank
3. Enter 20000 in Airtime Rate field
4. Enter "Additional airtime for online delivery"
5. Click Save
```

### Scenario 3: Both Rate Revisions
```
Lecturer: Prof. Smith
Original: 6,500/hr, no airtime
Revision: To 9,500/hr + 15,000 airtime

Action:
1. Click "Edit Rates"
2. Enter 9500 in Hourly Rate
3. Enter 15000 in Airtime Rate
4. Enter "Standard rate adjustment + airtime allowance"
5. Click Save
```

---

## What Happens After You Save

1. ✅ Rates are updated in the system
2. ✅ Total amount recalculates automatically (if hourly rate changed)
3. ✅ Revision is recorded with:
   - New rates
   - Your name (finance officer)
   - Timestamp
   - Your reason
4. ✅ You can now proceed to **Approve** or **Pay** the request

---

## Viewing Revised Rates

If you need to see what rates were revised:

1. Access the database directly, OR
2. Check the request details (if available in future UI update)

The system records:
- `revised_hourly_rate` - New hourly rate
- `revised_airtime_rate` - New airtime rate
- `rate_revision_reason` - Your reason
- `revised_by` - Your user ID
- `revised_at` - When it was changed

---

## Troubleshooting

### "Edit Rates" Button is Greyed Out
**Cause:** Request is already paid
**Solution:** You can only edit rates for pending/approved requests

### No "Edit Rates" Button Visible
**Cause:** You don't have Finance role
**Solution:** Ask admin to assign Finance role to your account

### Error When Saving: "Please enter at least one revised rate"
**Cause:** Both rate fields are empty
**Solution:** Enter at least hourly rate or airtime rate

### Changes Not Appearing
**Cause:** Page cache
**Solution:** Refresh the page (F5 or Ctrl+R)

---

## Best Practices

✅ **DO:**
- Always document the reason for rate revision
- Use consistent rate standards
- Review requests carefully before revision
- Process revisions before final approval

❌ **DON'T:**
- Arbitrarily change rates without reason
- Revise after payment processing
- Leave reason field blank
- Make multiple revisions of same request

---

## Questions?

If you have issues or questions:
1. Check this guide
2. Ask your Finance Manager
3. Contact IT Support

---

**Version:** 1.0
**Date:** March 19, 2026
**Status:** Active
