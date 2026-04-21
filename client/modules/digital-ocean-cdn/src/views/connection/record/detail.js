Espo.define(
  "digital-ocean-cdn:views/connection/record/detail",
  "views/connection/record/detail",
  (Dep) => {
    return Dep.extend({
      setup() {
        Dep.prototype.setup.call(this);

        if (this.model.get("type") === "doSpaces") {
          this.applyDoSpacesLayout();
        }

        this.listenTo(this.model, "change:type", () => {
          if (this.model.get("type") === "doSpaces") {
            this.applyDoSpacesLayout();
            this.reRender();
          } else if (this.model.previous("type") === "doSpaces") {
            this.detailLayout = null;
            this.reRender();
          }
        });
      },

      applyDoSpacesLayout() {
        this.detailLayout = [
          {
            label: "Details",
            rows: [
              [{ name: "name" }, { name: "type" }],
              [{ name: "doSpacesEndpoint" }, { name: "doSpacesRegion" }],
              [{ name: "doSpacesAccessKey" }, { name: "doSpacesSecretKey" }],
              [{ name: "doSpacesBucket" }, { name: "doSpacesCdnEndpoint" }],
            ],
          },
        ];
      },
    });
  }
);
