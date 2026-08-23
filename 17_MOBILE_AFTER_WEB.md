# REFCONCEPT MOBILE — DEFERRED UNTIL WEB RELEASE

This file is intentionally not part of the active Web build scope.

## Entry Condition

Mobile implementation may start only after:

```text
WEB_RELEASE_APPROVED
```

## Future Milestones

### Mobile Phase A
- Flutter foundation
- API client
- auth/profile
- projects/rooms
- AI designs
- credits
- catalog/favorites

### Mobile Phase B
- cart/checkout
- payment return/deep links
- orders
- shipping/refunds
- notifications

### Mobile Phase C
- iOS Swift RoomPlan/ARKit/RealityKit
- Android Kotlin ARCore/Depth
- 3D product placement
- capability fallbacks

## Rule

Mobile does not reimplement authoritative business logic.

Examples:
- credit deduction → server
- commission → server
- stock reserve → server
- order totals → server
- settlement → server
