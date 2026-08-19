-- The shop's default inspection sheet.
--
-- Items are recorded in whatever order the tech walks the car (§8), so nothing
-- here implies a sequence; sort_order only controls how the sheet renders on
-- the tablet. `required` is what drives the missing-items readback at
-- end_inspection, and it is deliberately a short list — reading back fifteen
-- missing items would be as useless as reading back none.
--
-- spoken_label exists so the readback reads like a sentence: "I don't have rear
-- brakes or the battery test", not "...or battery test".

INSERT INTO inspection_templates (id, name, active, created_at)
VALUES ('tpl_default', 'Shybes multi-point inspection', 1, '2026-08-18T00:00:00Z');

INSERT INTO inspection_template_items (id, template_id, field_key, label, spoken_label, unit, required, sort_order, synonyms) VALUES
('tpi_01', 'tpl_default', 'front_brakes',  'Front brakes',      'front brakes',        'mm',  1,  10, '["front pads","front pad","pads","front rotors","fronts"]'),
('tpi_02', 'tpl_default', 'rear_brakes',   'Rear brakes',       'rear brakes',         'mm',  1,  20, '["rear pads","rear shoes","backs","rears"]'),
('tpi_03', 'tpl_default', 'tire_lf',       'Left front tire',   'the left front tire', '/32', 1,  30, '["left front","lf tire","driver front"]'),
('tpi_04', 'tpl_default', 'tire_rf',       'Right front tire',  'the right front tire','/32', 1,  40, '["right front","rf tire","passenger front"]'),
('tpi_05', 'tpl_default', 'tire_lr',       'Left rear tire',    'the left rear tire',  '/32', 1,  50, '["left rear","lr tire","driver rear"]'),
('tpi_06', 'tpl_default', 'tire_rr',       'Right rear tire',   'the right rear tire', '/32', 1,  60, '["right rear","rr tire","passenger rear"]'),
('tpi_07', 'tpl_default', 'battery_test',  'Battery test',      'the battery test',    'V',   1,  70, '["battery","batt","cranking amps","charging system"]'),
('tpi_08', 'tpl_default', 'engine_oil',    'Engine oil',        'the engine oil',      NULL,  1,  80, '["oil level","oil condition","motor oil"]'),
('tpi_09', 'tpl_default', 'coolant',       'Coolant',           'the coolant',         NULL,  1,  90, '["antifreeze","coolant level"]'),
('tpi_10', 'tpl_default', 'brake_fluid',   'Brake fluid',       'the brake fluid',     NULL,  1, 100, '["brake fluid level","master cylinder"]'),
('tpi_11', 'tpl_default', 'air_filter',    'Engine air filter', 'the air filter',      NULL,  0, 110, '["air filter","intake filter"]'),
('tpi_12', 'tpl_default', 'cabin_filter',  'Cabin air filter',  'the cabin filter',    NULL,  0, 120, '["cabin filter","pollen filter"]'),
('tpi_13', 'tpl_default', 'wipers',        'Wiper blades',      'the wipers',          NULL,  0, 130, '["wipers","blades","wiper"]'),
('tpi_14', 'tpl_default', 'lights',        'Exterior lights',   'the lights',          NULL,  0, 140, '["lights","headlights","brake lights","turn signals"]'),
('tpi_15', 'tpl_default', 'belts_hoses',   'Belts and hoses',   'belts and hoses',     NULL,  0, 150, '["serpentine","belt","hoses","hose"]'),
('tpi_16', 'tpl_default', 'suspension',    'Suspension',        'the suspension',      NULL,  0, 160, '["struts","shocks","ball joints","tie rods","bushings"]'),
('tpi_17', 'tpl_default', 'exhaust',       'Exhaust',           'the exhaust',         NULL,  0, 170, '["muffler","exhaust leak","pipes"]'),
('tpi_18', 'tpl_default', 'trans_fluid',   'Transmission fluid','the transmission fluid', NULL, 0, 180, '["trans fluid","atf","gear oil"]');
