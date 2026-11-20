# Suchbegriffe für Ausbildungsgesuche

Canonical Ausbildung taxonomy for Joinera matching and intake search.

- **Ausbildungsweg (industry)** — one of 8 training paths. Admin UI filter only; not stored.
- **Schwerpunkt (focus)** — workplace specialization Staff selects. Stored in `profile.industry[]`.
- **Suchbegriffe** — DE search synonyms for intake/crawl only; **not** shown as selectable chips.
- **match_key** — synonym family for hard match (`IndustryTaxonomy::familyKey`).

Hard match: specialization slugs and Suchbegriffe in the same family match each other.

---

## Pflegefachkraft (`pflegefachkraft`)

- **Schwerpunkte:** Krankenhaus · Altenpflege · Ambulante Pflege  
  (`krankenhaus`, `altenpflege`, `ambulante_pflege`)
- **Suchbegriffe:** Pflegefachmann; Pflegefachfrau; Pflegefachkraft; Gesundheits- und Krankenpfleger; Gesundheits- und Krankenpflegerin; Krankenpfleger; Krankenschwester; Altenpfleger; Altenpflegerin

## Pflegeassistenz (`pflegeassistent`)

- **Schwerpunkte:** Krankenhaus · Altenpflege · Ambulante Pflege  
  (`assistenz_krankenhaus`, `assistenz_altenpflege`, `assistenz_ambulant`)
- **Suchbegriffe:** Pflegeassistent; Pflegeassistentin; Pflegefachassistent; Pflegefachassistentin; Krankenpflegehelfer; Krankenpflegehelferin; Altenpflegehelfer; Altenpflegehelferin

## Pflegehilfe (`pflegehelfer`)

- **Schwerpunkte:** Krankenhaus · Altenpflege · Ambulante Pflege  
  (`hilfe_krankenhaus`, `hilfe_altenpflege`, `hilfe_ambulant`)
- **Suchbegriffe:** Pflegehelfer; Pflegehelferin; Pflegehilfskraft; Pflegehilfe

## MFA (`mfa`)

- **Schwerpunkte:** Hausarztpraxis · Facharztpraxis · Klinik / MVZ  
  (`hausarztpraxis`, `facharztpraxis`, `klinik_mvz`)
- **Suchbegriffe:** MFA; Medizinischer Fachangestellter; Medizinische Fachangestellte; Arzthelfer; Arzthelferin

## ZFA (`zfa`)

- **Schwerpunkte:** Zahnarztpraxis · Kieferorthopädie · Oralchirurgie  
  (`zahnarztpraxis`, `kieferorthopaedie`, `oralchirurgie`)
- **Suchbegriffe:** ZFA; Zahnmedizinischer Fachangestellter; Zahnmedizinische Fachangestellte; Zahnarzthelfer; Zahnarzthelferin; Zahnarztassistenz

## Gastronomie (`gastronomie`)

- **Schwerpunkte:** Restaurant · Systemgastronomie · Hotel / Service  
  (`restaurant`, `systemgastronomie`, `hotel_service`)
- **Suchbegriffe:** Gastronomiefachkraft; Fachkraft für Gastronomie; Restaurantfachmann; Restaurantfachfrau; Fachmann für Systemgastronomie; Fachfrau für Systemgastronomie

## Koch / Köchin (`koch`)

- **Schwerpunkte:** Restaurantküche · Hotel / Kantine · Systemgastronomie  
  (`restaurantkueche`, `hotel_kantinenkueche`, `systemkueche`)
- **Suchbegriffe:** Koch; Köchin

## Elektronik / Elektro (`elektriker`)

- **Schwerpunkte:** Energie- und Gebäudetechnik · Automatisierungs- und Systemtechnik · Informations- und Telekommunikationstechnik  
  (`gebaeudetechnik`, `automatisierungstechnik`, `informationstechnik`)
- **Suchbegriffe:** Elektriker; Elektrikerin; Elektroniker; Elektronikerin; Elektroinstallateur; Elektroinstallateurin; Elektrofachkraft
