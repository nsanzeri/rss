# Full US ZIP Code Import (GeoNames)

Ready Set Shows uses a `zipcodes` table (ZIP → lat/lng) to power **ZIP-based radius search** on the public landing page.

This repo includes a small seed set for Chicagoland, but you can import the **full US ZIP table** using GeoNames.

## Data source + license

Data comes from GeoNames postal code dumps (`US.zip`) and is available under **Creative Commons Attribution 4.0**.

When you use this data in a public product, you should give credit to GeoNames (a link to geonames.org is typically sufficient).

## Steps (XAMPP / Windows)

1. Configure your DB credentials in `.env` (or wherever your project reads DB settings).
2. From the project root, run:

   ```bash
   php scripts/import_zipcodes_geonames.php
   ```

3. Once it completes, the landing page ZIP search will work for *any* US ZIP.

## Re-running / refreshing

If you want to refresh everything (not required), you can:

```sql
TRUNCATE TABLE zipcodes;
```

Then re-run the import script.

## What gets stored

We store a single row per 5-digit ZIP:

`zip, lat, lng, city, state`

GeoNames may contain multiple place names per ZIP; we `INSERT IGNORE` and keep the first record encountered.
