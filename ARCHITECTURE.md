Below is the **canonical architecture**, based on:

* the current schema
* the features you’ve already built (search, profiles, bookings, media)
* the direction you’ve clearly been steering this (marketplace + ops tool)

I’ll keep this concrete and actionable.

---

# ✅ Ready Set Shows — Locked Architecture (v1)

This is the mental model we will *not* fight going forward.

---

## 1️⃣ Core domains (clean separation)

### **Users**

* Authentication
* Account status
* Membership / billing state

**Users do NOT own content directly** — they own *profiles*.

This is already true in your schema and it’s correct.

---

### **Profiles** (the public-facing entity)

Profiles are *what the world sees*.

A profile:

* belongs to a user
* has a type (`artist`, later `venue`, `planner`)
* is searchable
* is bookable
* owns media + reviews

You already have:

```sql
profiles
- id
- user_id
- profile_type
- name
- city/state/zip
- genres
- bio
- website
- is_active
- deleted_at
```

✅ This is solid. We keep it.

---

## 2️⃣ Media model (locked)

### **Photos**

**1-to-many** with profiles

Rules:

* Many photos per profile
* Exactly **one primary** photo (enforced in code, not DB)
* Stored under `/public/uploads/profiles/{profile_id}/`

Table:

```
profile_photos
- id
- profile_id
- file_path
- is_primary
- sort_order
- created_at
```

Public display:

* Gallery at top of profile
* Primary photo first
* Thumbnails after

Admin:

* Upload
* Make primary
* Delete

✅ You’re already here. We keep it.

---

### **YouTube / Videos**

**1-to-many** with profiles

Rules:

* Store raw URL + extracted `youtube_id`
* Some videos can be marked **featured**
* Only featured videos display publicly

Table:

```
profile_youtube_links
- id
- profile_id
- youtube_url
- youtube_id
- is_featured
- created_at
```

Future-proof:

* We can later add Instagram/TikTok without touching profiles.

---

## 3️⃣ Bookings (this is important)

Bookings are **first-class workflow objects**, not emails.

### **booking_requests** (canonical)

A booking request:

* is created by a visitor
* targets a *primary profile*
* may be auto-sent to other profiles
* moves through statuses

Minimum lifecycle:

```
new → sent → responded → confirmed → closed / canceled
```

Core table:

```
booking_requests
- id
- primary_profile_id
- requester_name
- requester_email
- requester_phone
- event_date
- event_time
- venue_name
- venue_city/state
- notes
- created_at
```

Recipients table (already discussed earlier):

```
booking_request_recipients
- id
- booking_request_id
- profile_id
- recipient_type (primary / auto)
- created_at
```

Why this matters:

* response time becomes **real data**
* reviews can later be tied to real bookings
* analytics becomes trivial

---

## 4️⃣ Reviews (trust signals)

Reviews belong to **profiles**, not users.

Rules (locked):

* Anyone can submit a review
* **Only paid profiles display reviews**
* Ratings are averaged per profile
* Reviews may be moderated later

Table:

```
reviews
- id
- profile_id
- reviewer_name
- rating (1–5)
- comment
- is_approved
- created_at
```

Public logic:

```php
if (profile_is_paid) {
  show reviews + rating
} else {
  show “Reviews available for paid members”
}
```

---

## 5️⃣ Membership / monetization (the gatekeeper)

This is the one thing we should **centralize early**.

### Minimal version (recommended)

Add to `users`:

```
plan ENUM('free','paid')
paid_until DATETIME NULL
```

OR (cleaner long-term):

```
memberships
- id
- user_id
- plan
- status
- current_period_end
```

All feature gating flows through helpers:

* `is_paid_user($user_id)`
* `profile_can_show_reviews($profile_id)`
* `profile_can_be_auto_added($profile_id)`

**Never inline plan checks in random pages.**

---

## 6️⃣ Search (finalized behavior)

Two modes — already implemented correctly:

### Mode A: Anywhere

* radius = 0
* WHO required
* name-based search only

### Mode B: Radius

* zip required
* geo bounding + haversine
* optional WHO filter

This stays. We will only improve **ranking and cosmetics**, not logic.

---

## 7️⃣ Public Profile Layout (locked)

Top-down structure:

1. **Photo gallery** (hero)
2. Name + city/state + genres
3. Bio
4. Featured videos
5. Booking info:

   * Payment terms (copy-based for now)
   * Typical response time (computed later)
6. Reviews (paid-only)
7. Call to action: “Request Booking”

This matches what you’re building *and* what users expect.

---

## What we do next (recommended order)

Now that architecture is locked, the highest ROI next steps are:

### 🔜 Next pass (pick one)

1. **Bookings pass**

   * Request form UX
   * Auto-add logic (paid-only)
   * Response tracking (timestamps)

2. **Search cosmetics pass**

   * Header + filters in one row
   * Better cards (photo + rating + CTA)
   * Sort by relevance

3. **Membership pass**

   * Define paid perks clearly
   * Hide/show UI affordances cleanly
   * Prep for Stripe later

---

### My recommendation

Do **Bookings pass next**.

Why:

* It unlocks response-time metrics
* It justifies paid membership
* It ties together profiles, reviews, and trust

When you’re ready, say:

> “Let’s do the Bookings pass.”

And we’ll work from the schema outward, cleanly, without fighting the system.
