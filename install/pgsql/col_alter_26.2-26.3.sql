SET check_function_bodies = false;
-- ddl-end --

SET search_path=public,pg_catalog,col,gacl;
-- ddl-end --

-- ** [ Created objects ]

-- object: col.collection_igsn | type: TABLE --
-- DROP TABLE IF EXISTS col.collection_igsn CASCADE;
CREATE TABLE col.collection_igsn (
	collection_id integer NOT NULL,
	api_key varchar NOT NULL,
	dataset_template_id integer NOT NULL,
	CONSTRAINT collection_igsn_pk PRIMARY KEY (collection_id)
);
-- ddl-end --
COMMENT ON TABLE col.collection_igsn IS E'Settings of the publication of the samples of a collection into the IGSN registry';
-- ddl-end --
COMMENT ON COLUMN col.collection_igsn.api_key IS E'API key of the service account of the IGSN registry used for the collection, encrypted with the public key of the instance (app.pubKey)';
-- ddl-end --
COMMENT ON COLUMN col.collection_igsn.dataset_template_id IS E'Dataset template of type igsn used to generate the IGSN records of the samples';
-- ddl-end --

-- object: col.igsn_publication | type: TABLE --
-- DROP TABLE IF EXISTS col.igsn_publication CASCADE;
CREATE TABLE col.igsn_publication (
	uid integer NOT NULL,
	collection_id integer NOT NULL,
	batch_id uuid,
	igsn varchar,
	igsn_status varchar NOT NULL,
	igsn_error varchar,
	claim_token uuid,
	claimed_at timestamp,
	updated_at timestamp NOT NULL DEFAULT now(),
	CONSTRAINT igsn_publication_pk PRIMARY KEY (uid)
);
-- ddl-end --
COMMENT ON TABLE col.igsn_publication IS E'Publication of the samples into the IGSN registry';
-- ddl-end --
COMMENT ON COLUMN col.igsn_publication.collection_id IS E'Collection whose API key and webhook secret were used to send the last batch';
-- ddl-end --
COMMENT ON COLUMN col.igsn_publication.batch_id IS E'Identifier of the last batch of the registry which contained the sample';
-- ddl-end --
COMMENT ON COLUMN col.igsn_publication.igsn IS E'IGSN minted by the registry';
-- ddl-end --
COMMENT ON COLUMN col.igsn_publication.igsn_status IS E'Status of the publication, as given by the registry (publishing, published, publish_failed, withdrawn, tombstone, draft), or submitting (first sending in progress), untracked (batch no longer readable)';
-- ddl-end --
COMMENT ON COLUMN col.igsn_publication.igsn_error IS E'Reason of the last failed publication';
-- ddl-end --
COMMENT ON COLUMN col.igsn_publication.claim_token IS E'Token of the request which is sending the sample, so that it is not sent twice at the same time';
-- ddl-end --
COMMENT ON COLUMN col.igsn_publication.claimed_at IS E'Date of the claim, a claim older than 15 minutes is abandoned';
-- ddl-end --
COMMENT ON COLUMN col.igsn_publication.updated_at IS E'Date of the last change of the publication';
-- ddl-end --

-- object: igsn_publication_batch_idx | type: INDEX --
-- DROP INDEX IF EXISTS col.igsn_publication_batch_idx CASCADE;
CREATE INDEX igsn_publication_batch_idx ON col.igsn_publication
USING btree
(
	batch_id
);
-- ddl-end --

-- object: igsn_publication_claim_idx | type: INDEX --
-- DROP INDEX IF EXISTS col.igsn_publication_claim_idx CASCADE;
CREATE INDEX igsn_publication_claim_idx ON col.igsn_publication
USING btree
(
	claim_token
)
WHERE (claim_token IS NOT NULL);
-- ddl-end --

INSERT INTO col.dataset_type (dataset_type_id, dataset_type_name, fields)
SELECT 6, 'igsn', '["uid","uuid","identifier","wgs84_x","wgs84_y","location_accuracy","object_status_name","referent_name","referent_email","address_name","address_line2","address_line3","address_city","address_country","referent_phone","referent_firstname","academic_directory","academical_link","referent_organization","object_comment","identifiers","sample_creation_date","sampling_date","multiple_value","sampling_place_name","expiration_date","sample_type_name","product_name","risk_name","multiple_type_name","collection_name","metadata","metadata_unit","parent_uid","parent_uuid","parent_identifiers","storage_type_name","fixed_value","country_code2","country_name","country_origin_code2","campaign_id","campaign_name","campaign_uuid"]'
WHERE NOT EXISTS (SELECT 1 FROM col.dataset_type WHERE dataset_type_id = 6);
-- ddl-end --
SELECT setval('col.dataset_type_dataset_type_id_seq', (SELECT max(dataset_type_id) FROM col.dataset_type));
-- ddl-end --
COMMENT ON TABLE col.dataset_type IS E'Origine of the dataset: sample, collection, document, elabftw, igsn';
-- ddl-end --

-- ** [ Created foreign keys ]

-- object: collection_igsn_collection_fk | type: CONSTRAINT --
-- ALTER TABLE col.collection_igsn DROP CONSTRAINT IF EXISTS collection_igsn_collection_fk CASCADE;
ALTER TABLE col.collection_igsn ADD CONSTRAINT collection_igsn_collection_fk FOREIGN KEY (collection_id)
REFERENCES col.collection (collection_id) MATCH FULL
ON DELETE CASCADE ON UPDATE CASCADE;
-- ddl-end --

-- object: collection_igsn_dataset_template_fk | type: CONSTRAINT --
-- ALTER TABLE col.collection_igsn DROP CONSTRAINT IF EXISTS collection_igsn_dataset_template_fk CASCADE;
ALTER TABLE col.collection_igsn ADD CONSTRAINT collection_igsn_dataset_template_fk FOREIGN KEY (dataset_template_id)
REFERENCES col.dataset_template (dataset_template_id) MATCH FULL
ON DELETE RESTRICT ON UPDATE CASCADE;
-- ddl-end --

-- object: igsn_publication_collection_fk | type: CONSTRAINT --
-- ALTER TABLE col.igsn_publication DROP CONSTRAINT IF EXISTS igsn_publication_collection_fk CASCADE;
ALTER TABLE col.igsn_publication ADD CONSTRAINT igsn_publication_collection_fk FOREIGN KEY (collection_id)
REFERENCES col.collection (collection_id) MATCH FULL
ON DELETE CASCADE ON UPDATE CASCADE;
-- ddl-end --

-- object: igsn_publication_object_fk | type: CONSTRAINT --
-- ALTER TABLE col.igsn_publication DROP CONSTRAINT IF EXISTS igsn_publication_object_fk CASCADE;
ALTER TABLE col.igsn_publication ADD CONSTRAINT igsn_publication_object_fk FOREIGN KEY (uid)
REFERENCES col.object (uid) MATCH FULL
ON DELETE CASCADE ON UPDATE CASCADE;
-- ddl-end --

insert into col.dbversion(dbversion_number, dbversion_date) values ('26.3', '2026-10-05');
