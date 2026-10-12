# PDF.js 6.4.299

Official Mozilla generic viewer distribution:
https://github.com/mozilla/pdf.js/releases/tag/v6.4.299

Archive: `pdfjs-6.4.299-dist.zip`
SHA-256: `f2245eb7ef3f674c872fe568a177bfec15874f5c034613e55d8e307f93ebd86d`
Verified against the release asset digest. Apache-2.0; see `LICENSE` and included resource licenses.

Runtime files are unchanged. Source maps and the example PDF are omitted.
The application uses the stock HTML viewer so rendering does not depend on the browser's PDF plugin or its download preference. Preview fetches `import-file&preview=1` as authenticated JSON containing the linked file name and base64 data, then calls `PDFViewerApplication.open({ data, filename })`. No preview request has a PDF MIME type, filename disposition or raw PDF response that IDM can intercept. Normal `import-file` retains the explicit PDF download behavior. Uploaded PDFs are never copied here.

The parent's `webviewerloaded` listener selects Vietnamese, disables persisted viewer preferences, PDF scripting, annotation editing and optional remote alt-text model downloads before viewer initialization. The external file picker is hidden: the viewer opens the receipt's linked PDF automatically. The viewer supplies page navigation, zoom, search and rendering errors. Closing or switching previews cancels pending requests; failed loading shows an explicit error in the dialog.

To upgrade, copy the matching official generic release runtime files and resource directories together, retaining licenses, then run `node tests/home-ui.mjs`.
