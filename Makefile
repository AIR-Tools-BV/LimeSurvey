# AIR-Tools additions to upstream LimeSurvey. Upstream itself has no Makefile.

SEA_GREEN := themes/admin/Sea_Green/css
AIR_TOOLS := upload/admintheme/AIR_Tools/css
# Sea_Green's greens -> AIR-Tools navy (#03045E). Rerun after every upstream upgrade.
RECOLOR := sed -e 's/\#14[Aa][Ee]5[Cc]/\#03045E/g' -e 's/\#328637/\#03045E/g' -e 's/\#2[Dd]7[Aa]53/\#03045E/g' -e 's/20, 174, 92/3, 4, 94/g'

.PHONY: admintheme up down

admintheme:
	$(RECOLOR) $(SEA_GREEN)/sea_green.css > $(AIR_TOOLS)/air_tools.css
	$(RECOLOR) $(SEA_GREEN)/sea_green-rtl.css > $(AIR_TOOLS)/air_tools-rtl.css

up:
	docker compose up -d --build

down:
	docker compose down
