Espo.define(
  "digital-ocean-cdn:views/storage/record/detail",
  "views/storage/record/detail",
  (Dep) => {
    return Dep.extend({
      setup() {
        Dep.prototype.setup.call(this);

        this.detailLayout = [
          {
            label: "Details",
            rows: [
              [{ name: "name" }, { name: "isActive" }],
              [{ name: "type" }, { name: "folder" }],
              [{ name: "path" }, { name: "syncFolders" }],
              [{ name: "connection" }, { name: "bucket" }],
              [{ name: "cdnEndpoint" }, { name: "keyPrefix" }],
            ],
          },
        ];
      },
    });
  }
);
