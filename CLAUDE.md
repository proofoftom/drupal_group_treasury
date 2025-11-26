# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

The Group Treasury module integrates Safe Smart Accounts as multi-signature treasuries for Drupal Groups. Built on top of the Safe Smart Accounts module, it enables DAOs and collaborative groups to manage shared funds with on-chain security while maintaining Drupal's workflow and permission systems.

**Core Dependencies:**
- Drupal 10.x
- Group module 2.x (drupal/group ^2.3)
- Safe Smart Accounts module (safe_smart_accounts)
- PHP 8.3+ with GMP extension

## Development Environment

This module is developed within a DDEV environment. From the Drupal root (`/home/proofoftom/Code/drupal-group-dao`):

```bash
# Start environment
ddev start

# Enable module and clear cache
ddev drush en group_treasury -y && ddev drush cr

# For existing installations, run database updates to configure block visibility
ddev drush updb -y

# Check module status
ddev drush pm:list --filter=group_treasury

# View module logs
ddev drush watchdog:show --type=group_treasury --count=20

# Code standards check
ddev exec vendor/bin/phpcs --standard=Drupal,DrupalPractice web/modules/custom/group_treasury/

# Export configuration after changes
ddev drush config:export -y
```

### Block Visibility Auto-Configuration

When the module is installed, `hook_install()` automatically adds `/group/*/treasury` to the visibility paths of key Open Social blocks:
- `block.block.groupheroblock` - Group hero header
- `block.block.socialblue_groupheroblock` - Socialblue theme hero
- `block.block.socialblue_group_statistic_block` - Sidebar member count
- `block.block.socialblue_group_add_block` - Add content button
- `block.block.socialblue_group_add_event_block` - Add event button
- `block.block.socialblue_group_add_topic_block` - Add topic button

This ensures the Treasury tab appears with the full Group page layout (hero header + sidebar) like other Group tabs (Stream, Events, Topics).

**For existing installations**: Run `ddev drush updb` to apply the update hook `group_treasury_update_9001()` which adds the treasury path to existing block configurations.

## Architecture Overview

### Plugin System (Group Integration)

The module uses Group's plugin architecture to link Safe accounts to Groups:

**GroupSafeAccount** (`src/Plugin/Group/Relation/GroupSafeAccount.php`):
- GroupRelation plugin that establishes the Safe-to-Group relationship
- Uses annotation syntax (@GroupRelationType) for Group 2.x compatibility
- Enforces 1:1 cardinality (one treasury per Group)
- Provides entity_access integration for permission checks

**GroupSafeAccountDeriver** (`src/Plugin/Group/Relation/GroupSafeAccountDeriver.php`):
- Creates plugin derivative for safe_account entity type
- Single derivative since SafeAccount has no bundles

**GroupSafeAccountPermissionProvider** (`src/Plugin/Group/RelationHandler/GroupSafeAccountPermissionProvider.php`):
- Defines Group-level permissions for treasury operations
- Permissions: view, propose transactions, sign, execute, manage

### Service Layer

**GroupTreasuryService** (`src/Service/GroupTreasuryService.php`):
- Core business logic for treasury-group relationships
- Methods: `getTreasury()`, `hasTreasury()`, `addTreasury()`, `removeTreasury()`
- Manages cache invalidation when treasury relationships change

**TreasuryAccessibilityChecker** (`src/Service/TreasuryAccessibilityChecker.php`):
- Validates Safe accessibility via Safe Transaction Service API
- Returns accessibility status and error messages
- Used by controller to determine treasury tab states

### Controller & Routing

**GroupTreasuryController** (`src/Controller/GroupTreasuryController.php`):
- Main controller for `/group/{id}/treasury` tab
- Three states: no treasury, inaccessible treasury, active treasury
- Permission-aware action rendering

**Routes** (`group_treasury.routing.yml`):
- `group_treasury.treasury`: Main treasury tab
- `group_treasury.create`: Treasury creation form
- `group_treasury.reconnect`: Reconnect inaccessible treasury
- `group_treasury.propose_transaction`: Transaction proposal form

### Local Tasks & Tab Inheritance

**Challenge**: Treasury child routes (propose, create, reconnect) need to display Group tabs (Stream, Events, Topics, Treasury, Members) to maintain navigation consistency.

**Solution**: Define child routes as local tasks with `parent_id` referencing the treasury tab. This triggers Drupal's local task inheritance mechanism.

**Implementation** (`group_treasury.links.task.yml`):
```yaml
# Parent tab (visible in Group navigation)
group_treasury.treasury_tab:
  route_name: group_treasury.treasury
  base_route: entity.group.canonical
  title: 'Treasury'
  weight: 50

# Child routes (trigger parent tab inheritance)
group_treasury.propose_task:
  route_name: group_treasury.propose_transaction
  parent_id: group_treasury.treasury_tab
  title: 'Propose Transaction'
  weight: 100
```

**Critical Pattern**: When a child task has `parent_id`, it inherits the `base_route` from its parent, which makes Drupal render the entire tab group (all tabs with the same `base_route`) on the child route.

**navbar-secondary.js Conflict**:
The Open Social theme's `navbar-secondary.js` (responsible for tab overflow dropdown) breaks when both primary and secondary tabs are present. The script queries for ALL elements with class `.nav` and processes them together, which strips the `.navbar-scrollable` wrapper and `<ul>` elements from the DOM.

**Workaround** (`hook_preprocess_menu_local_tasks()`):
```php
function group_treasury_preprocess_menu_local_tasks(&$variables) {
  $route_name = \Drupal::routeMatch()->getRouteName();

  $treasury_routes = [
    'group_treasury.treasury',
    'group_treasury.propose_transaction',
    'group_treasury.create',
    'group_treasury.reconnect',
  ];

  // Remove secondary tabs - they exist only for inheritance
  // but break navbar-secondary.js when rendered
  if (in_array($route_name, $treasury_routes)) {
    $variables['secondary'] = [];
  }
}
```

**Result**: Group tabs display correctly on all treasury routes, and the overflow dropdown works when tabs exceed navbar width.

### Automatic Signer Synchronization

**Critical Pattern**: The module automatically proposes Safe signer changes when Group roles change. This maintains the principle that on-chain signers must approve all signer list modifications.

**Implementation** (`group_treasury.module`):
- Entity hooks: `hook_group_relationship_insert/update/delete()`
- When admin role assigned → propose `addOwnerWithThreshold` transaction
- When admin role removed → propose `removeOwner` transaction
- When member leaves Group → propose `removeOwner` if they're a signer

**Function Encoding**:
```php
// addOwnerWithThreshold(address owner, uint256 _threshold)
// Selector: 0x0d582f13
_group_treasury_encode_add_owner($address, $threshold)

// removeOwner(address prevOwner, address owner, uint256 _threshold)
// Selector: 0xf8dc5dd9
_group_treasury_encode_remove_owner($prev_owner, $address, $threshold)
```

**Safe Linked List Pattern**:
Safe stores owners in a linked list with SENTINEL_OWNER (`0x0000000000000000000000000000000000000001`) as the first element. The `_group_treasury_find_prev_owner()` function calculates the previous owner needed for removeOwner operations.

### Safe Accounts List Integration

**Hook Implementation** (`group_treasury.module:170-301`):
- `hook_preprocess_table()` intercepts Safe accounts list page
- Adds Group treasury rows for user's memberships
- Shows "Treasury Signer" role badge with Group context
- Provides "View Treasury" and "Propose Transaction" actions

**Display Pattern**:
- Query user's Group memberships
- Check each Group for treasury via `GroupTreasuryService`
- Verify user is signer via `SafeConfigurationService::getSafesForSigner()`
- Inject treasury rows into existing Safe accounts table

### Wizard Integration

**Group Creation Wizard** (`group_treasury.module:46-147`):
- Third-party setting: `creator_treasury_wizard` on GroupType
- When enabled, redirects new Group creators to treasury deployment
- Form alter hooks intercept group creation form
- Custom submit handlers redirect to `group_treasury.create` route

**TreasuryWizardStepForm** (`src/Form/TreasuryWizardStepForm.php`):
- Multi-step form for wizard integration
- Framework ready for Group module version-specific implementation

### Forms (Integration Points)

**TreasuryCreateForm** (`src/Form/TreasuryCreateForm.php`):
- Links Safe deployment to Group
- Should integrate Safe creation UI from safe_smart_accounts module

**TreasuryTransactionProposeForm** (`src/Form/TreasuryTransactionProposeForm.php`):
- Creates SafeTransaction entities for Group treasury
- Should reuse transaction form fields from safe_smart_accounts

**TreasuryReconnectForm** (`src/Form/TreasuryReconnectForm.php`):
- Updates Safe network configuration
- Handles inaccessible treasury recovery

## Permission System

The module uses two types of permissions that work together:

### Module-Level Permissions

Defined in `group_treasury.permissions.yml` for treasury **operations**:
- `view group_treasury`: View treasury tab and transaction history
- `propose group_treasury transactions`: Create transaction proposals
- `sign group_treasury transactions`: Add signatures to pending transactions
- `execute group_treasury transactions`: Submit fully-signed transactions to blockchain
- `manage group_treasury`: Add/remove/reconnect treasury Safe accounts

**Usage**: Route access control (via `TreasuryAccessControlHandler`) and UI element visibility (via controller permission checks).

### Group Relation Permissions

Defined in `GroupSafeAccountPermissionProvider` for treasury **entity CRUD**:
- `view group_safe_account:safe_account entity`: View the Safe entity relationship
- `create group_safe_account:safe_account entity`: Add a Safe as the group treasury
- `delete group_safe_account:safe_account entity`: Remove the treasury relationship

**Usage**: Entity-level access control for the Safe-to-Group relationship.

### Permission Configuration

Both permission types are **configurable per Group Type**:
- Navigate to `/admin/group/types/manage/{type}/permissions`
- Each Group Type (e.g., `flexible_group`, `dao`) has separate role configurations
- Provides granular control over which roles can perform treasury operations

**Default assignments** (via `group_treasury_update_9003()`):
- **Group Managers**: All module-level + entity CRUD permissions
- **Members**: View and propose permissions only

Permission checks use Group's membership system: `$membership->hasPermission('permission_name')`.

## Nonce Management

**Critical**: Safe transactions must be executed in sequential nonce order (0, 1, 2, etc.). When proposing automatic signer changes:

```php
// Calculate next nonce for new transaction
$query = $transaction_storage->getQuery()
  ->condition('safe_account', $safe_account->id())
  ->condition('nonce', NULL, 'IS NOT NULL')  // Include nonce=0
  ->sort('nonce', 'DESC')
  ->range(0, 1);
$last_nonce = empty($result) ? -1 : $last_tx->get('nonce')->value;
$next_nonce = $last_nonce + 1;
```

**Common Pitfall**: Using `->condition('nonce', '', '<>')` excludes nonce=0 transactions.

## Cache Management

**Pattern**: Always use entity API methods, never direct SQL:

```php
// ✅ CORRECT - Triggers cache invalidation
$relationship = $group->addRelationship($safe_account, 'group_safe_account:safe_account');
$this->cacheTagsInvalidator->invalidateTags($group->getCacheTags());

// ❌ WRONG - Bypasses cache system
ddev drush sqlq "UPDATE group_relationship SET ...";
```

The GroupTreasuryService explicitly invalidates Group cache tags when treasury relationships change.

## Event Subscribers (Deprecated Approach)

**Note**: The module initially scaffolded EventSubscriber classes (`GroupRoleAssignSubscriber`, `GroupRoleRemoveSubscriber`) but the actual implementation uses entity hooks (`hook_group_relationship_insert/update/delete()`) directly in `group_treasury.module`.

The entity hook approach is more reliable for catching Group membership changes across all Group module versions.

## Testing & Validation

**Manual Testing Scenarios**:
Manual browser testing scenarios are defined in the parent project at:
`/home/proofoftom/Code/drupal-group-dao/specs/004-we-want-to/quickstart.md`

**Common Validation Commands**:
```bash
# Check if Group has treasury
ddev drush entity:query group_relationship --filter="gid=X,plugin_id=group_safe_account:safe_account"

# Verify transaction nonces
ddev drush sql-query "SELECT id, nonce, status, data FROM safe_transaction WHERE safe_account = X ORDER BY nonce"

# Check for duplicate nonces (indicates bug)
ddev drush sql-query "SELECT nonce, COUNT(*) FROM safe_transaction WHERE safe_account = X GROUP BY nonce HAVING COUNT(*) > 1"

# View Group treasury relationships
ddev drush sql-query "SELECT gr.id, gr.gid, gr.entity_id, sa.safe_address FROM group_relationship gr JOIN safe_account sa ON gr.entity_id = sa.id WHERE gr.plugin_id LIKE 'group_safe_account%'"
```

## Operations & Troubleshooting

**Treasury tab shows "No treasury"**:
1. Verify group_safe_account plugin enabled on GroupType
2. Check user has "view group_treasury" permission
3. Query group_relationship table for treasury link

**Treasury shows as "inaccessible"**:
1. Verify Safe Transaction Service API is accessible
2. Check Safe exists on blockchain (use block explorer)
3. Use "Reconnect Treasury" to update network configuration
4. Check Safe status: only 'active' Safes are checked for accessibility

**Automatic signer sync not working**:
1. Verify Group has active treasury (not pending/error status)
2. Check user has `field_ethereum_address` populated
3. Verify user's role matches expected admin role pattern: `{group_type}-admin`
4. Check for duplicate nonce issues in safe_transaction table

**"Add Treasury" operation not showing**:
1. Verify `hook_group_operations_alter()` implementation
2. Check Group doesn't already have treasury relationship
3. Clear Drupal cache: `ddev drush cr`

## Configuration Management

After making changes to permissions, routing, or plugin configuration:

```bash
# Export all configuration
ddev drush config:export -y

# Import module configuration only
ddev drush config:import --partial --source=modules/custom/group_treasury/config/install
```

## Multi-Group Treasury Support (Stretch Goal)

The architecture supports multiple Groups sharing a single Safe (group cardinality = 0), though this is not currently recommended:
- Multiple Groups can reference the same SafeAccount entity
- All participating Groups can propose transactions
- Signer management complexity increases (role changes in any Group affect shared Safe)
- **Current recommendation**: 1:1 relationship (one treasury per Group)

## Development Philosophy

This module follows the constitutional development principles defined in `/home/proofoftom/Code/drupal-group-dao/.specify/memory/constitution.md`:
- Feature-first development with testable increments
- Manual validation at each checkpoint
- Entity-first approach (Drupal workflow → blockchain integration)
- Security through on-chain threshold requirements
