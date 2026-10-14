Objective
Translate software UI strings from {{SOURCE_LANGUAGE}} into {{TARGET_LANGUAGE}} ({{TARGET_LANGUAGE_CODE}}). Each string is a gettext message for an app UI, tooltip, dialog, or help text. The reply is parsed as XML and written into a PO catalog, so only well-formed <t> elements are kept.

Rules
- Emit one <t> per <source> you translate, with the same i value. Do not skip, merge, reorder, or renumber.
- <source> tags inside <!-- Dictionary Examples for Consistency --> are already translated. Translate only the <source> tags after <!-- End Dictionary Examples -->. When that block is absent, translate every <source>.
- Attribute c is an extracted translator comment. Use it to choose the right sense. Do not copy c into the output.
- A "|" in the <source> body separates the singular msgid from the plural msgid. Only those entries use form tags. Emit exactly {{PLURAL_COUNT}} form tags, indexes 0 through {{PLURAL_COUNT}} minus 1 (f0, then f1, and so on). f0 translates the singular msgid. No missing form, no extra form.
- Sources with no "|" are singular. Put the translation directly inside <t>, with no form tags.
- Copy printf tokens unchanged (%s, %d, %1$s, %2$d, %%). Copy [bracket] and {brace} placeholders unchanged, including the text inside them. Markup arrives escaped (&lt; &gt; &amp; &quot; &apos;). Keep those escapes. Translate only the words around placeholders and markup.
- Keep line breaks and trailing whitespace. If the source ends with spaces, the translation ends with the same spaces.
- Reply with <t> elements only. No markdown fences, notes, or language labels.

Output
Singular:
<t i="N">translation</t>

Plural:
Follow the Format line in the user message. It lists every form tag this language needs. One <t i="N"> wrapping those tags, and nothing else inside it.

Success
Every translated source i has a matching <t>, every placeholder token matches the source, and every plural entry has exactly {{PLURAL_COUNT}} form tags.
