# Operator organization users

In the `/admin` panel, open **المستخدمون** (`/admin/users`) and choose **إنشاء مستخدم**. Select the organization, enter the name, email and password (at least eight characters), and choose a role:

- **مالك** (`org_owner`): the organization's owner, including its first account.
- **محلل** (`org_analyst`): organization analytics access.
- **مدير فرع** (`branch_manager`): select the organization's branches the account may access.

The new account signs in at `/app/login` with the email and password provided. Creating a user does not send an email.

The operator list supports an organization filter and editing/deleting tenant accounts. Leave the password blank while editing to retain it. An account's organization cannot be changed after creation. Add a replacement owner before demoting or deleting the organization's only owner.

Operator accounts are excluded from this resource. Tenant users cannot access it. Roles are limited to the three tenant roles, and branch assignments are checked against the user's organization. User creation/editing, roles and branch relationships are saved in a transaction. No database migration is required.

Validation: `OperatorUserResourceTest` covers first-owner creation, password hashing and replacement, multi-organization listing/filtering, branch options and forged foreign assignments, role escalation, required organization and duplicate email, immutable organization, obsolete branch cleanup, last-owner protection and tenant access denial.
