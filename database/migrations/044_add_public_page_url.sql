-- The workshop's own public/marketing site (e.g. a landing page) lives
-- entirely outside GarageOS — this just stores a link to it so staff can
-- jump there from the account menu, instead of hardcoding one org's URL
-- into the app itself.

ALTER TABLE organizations
    ADD COLUMN public_page_url VARCHAR(500);
