UPDATE ezsite_data SET value='4.4.0' WHERE name='ezpublish-version';
UPDATE ezsite_data SET value='1' WHERE name='ezpublish-release';

ALTER TABLE ezcontentobject DROP COLUMN is_published;

ALTER TABLE ezsection ADD identifier VARCHAR2(255) NULL;

CREATE INDEX ezinfocollection_att_ca_id ON ezinfocollection_attribute( contentclass_attribute_id );
CREATE INDEX ezinfocollection_att_oa_id ON ezinfocollection_attribute( contentobject_attribute_id );
CREATE INDEX ezinfocollection_att_in_id ON ezinfocollection_attribute( informationcollection_id );

ALTER TABLE ezpreferences MODIFY ( value LONG );
ALTER TABLE ezpolicy ADD original_id INTEGER DEFAULT 0 NOT NULL;
CREATE INDEX ezpolicy_original_id ON ezpolicy( original_id );

UPDATE ezcontentclass_attribute SET can_translate=0 WHERE data_type_string='ezuser';