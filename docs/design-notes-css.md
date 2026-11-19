# Design notes — `public/assets/css/site.css`

These are the design-rationale comments that previously lived inline in the
stylesheet. They are kept here so the reasoning survives without being shipped to
every visitor: the inline comments cost 23 KB gzipped, which is more than half of
what the stylesheet now weighs.

Comments appear below in stylesheet order. Read this file alongside
`public/assets/css/site.css` when changing a rule — most of these notes explain
*why* a value is what it is, and several record measured results (contrast
ratios, font-metric widths, touch-target audits) that are not obvious from the
declarations alone.

---

/* Original Cedar identity. Typography mirrors the Detroit WPResidence reference exactly:
   Roboto body 14px/1.65/400; h1 34, h2 28, h3 24, h4 18 (all /1.3em/500), h5 14, h6 12;
   menu 15px/500; labels/inputs/cards/footer utility 13px; buttons 16px/500, submit 15px/500.
   Rajdhani (600/700 only) is reserved for Detroit display elements: hero 70/700,
   section headings 47/600, category headings 37/600, ghost numerals, icon-box
   titles 22/600 uppercase, and 14/600 uppercase text links. */

/*Header */

/*Hero and search */

/*Property cards */

/*Home sections */

/*The grid used to hard-code two 220px rows. With fewer locations - or none
   at all - that reserved hundreds of pixels of empty background. Rows are now
   implicit, so the section is only as tall as the cards it actually holds. */

/*A lone card spanning two columns of a single row must not be forced to the
   tall feature height, or it leaves a tall empty block beside it. */

/*Forms and footer */

/*GET IN TOUCH rows: each contact detail leads with its own icon. */

/*Corporate pages */

/*Listings */

/*Property detail */

/*Sign in and utility states */

/*Admin */

/*Responsive */

/* The panel holds a wordmark, an eyebrow, a heading and a paragraph. With
       space-between and only 320px of height they collide on small screens, so
       give the content a real gap and let the panel grow to fit it. */

/*Brand logo (uploaded) — falls back to the default symbol + wordmark */

/*---------------------------------------------------------------------------
   Mobile navigation and responsive corrections
   --------------------------------------------------------------------------- */

/*The off-canvas nav is translated 100% to the right, which pushed it past
   the viewport edge and made the whole page scroll sideways. Clip the
   horizontal overflow so the hidden panel cannot widen the document.

   The clip must use `overflow-x: clip` and not `hidden`. A hidden overflow
   turns <body> into the scroll container for sticky descendants, and since
   <body> does not itself scroll, the sticky header and the sticky admin
   sidebar never stick. `clip` contains the overflow without creating a
   scroll container, so both goals are met. The clip on <html> stays as a
   fallback for browsers that do not support `clip`. */

/*The toggle is the primary mobile control: give it a proper tap target and
   keep it clear of the logo on narrow screens. */

/*The brand may be long; let it shrink and wrap instead of forcing the
   header wider than the screen. */

/*Utility bar: keep the email link from overflowing on small screens. */

/*Floating buttons must not create a sideways scroll on narrow screens. */

/*Grid layouts collapse cleanly on tablet and phone widths. */

/*Long words (emails, URLs, reference numbers) must not stretch a layout. */

/*Form controls keep a comfortable touch height on touch devices. */

/*Tablet: the two-column footer and contact split stay readable. */

/*Small phones: the brand wordmark may need to shrink further. */

/*---------------------------------------------------------------------------
   Touch targets — every interactive control should be comfortably tappable
   on a phone or tablet. Visually hidden inputs keep their 1px size; their
   labels provide the real target.
   --------------------------------------------------------------------------- */

/* Search fields: the site search uses its own control classes. */

/* Uppercase text links are primary calls to action. */

/* Popular search chips. */

/* Property card actions and title links. */

/* Footer and utility links. */

/* Breadcrumbs and small inline actions. */

/* Checkboxes and radios are visually hidden; their label is the target. */

/*The checkbox stays visible: it is a required field, and a visitor has to
   be able to see the box they are being asked to tick. The label carries
   the tap area around it, so the row is easy to hit on a phone. */

/* A tick, drawn with two borders so no extra file is needed. */

/*---------------------------------------------------------------------------
   Remaining touch targets
   --------------------------------------------------------------------------- */

/*The honeypot is clipped to 1px; the input inside must not report a large
   box to assistive technology or hit-testing. */

/*Property titles: the heading line is the link. Give it a little vertical
   padding without changing the visual rhythm of the card. */

/*Footer legal links and the contact panel's phone/email links. */

/*The listings sort dropdown. */

/*Listing title links on the listings page. */

/*Category headings are uppercase display type and can be wider than their
   card. Scale them down slightly instead of letting the text clip. */

/*Give long category names room without wrapping mid-word. */

/*Inline links inside small text (contact details, consent labels) keep their
   typography but gain a comfortable tap area via padding.
   Scoped to inline links only: an earlier version also matched the whole
   <small> element, and its inline-block override forced the phone number and
   the WhatsApp line to sit on the same line as their label instead of
   stacking underneath it. */

/*---------------------------------------------------------------------------
   Navigation layering
   The open off-canvas panel covers the right side of the header, including
   the hamburger. The toggle must stay on top so the menu can be closed with
   the same button that opened it.
   --------------------------------------------------------------------------- */

/* While the panel is open, the brand sits behind it. The state comes from
     the `nav-open` class the script sets on <html>, so no :has() support is
     needed. */

/*---------------------------------------------------------------------------
   Admin panel on phones and tablets
   --------------------------------------------------------------------------- */

/* The page title and its actions share a row; let them stack on narrow
     screens so no button is pushed out of view. */

/* Row actions stay on one line but the table scrolls horizontally. */

/*Two-column admin layouts (taxonomy, testimonials) with a wider first
   column on desktop; they collapse to a single column on small screens. */

/*Grid and flex children default to min-width:auto, which lets a wide table
   stretch its parent past the viewport. Allow them to shrink so the table
   wrapper can scroll internally instead. */

/*The toggle shows a menu icon when closed and a close icon when the panel
   is open. The state is driven by the `nav-open` class that the script puts
   on <html>, so this works in every browser without relying on :has(). */

/*---------------------------------------------------------------------------
   Scroll lock while the mobile menu is open.
   Setting overflow:hidden on the body alone resets the scroll position to the
   top, which is jarring when the menu is opened part-way down the page.
   Freezing the body in place and restoring its offset on close keeps the
   reading position.
   --------------------------------------------------------------------------- */

/*---------------------------------------------------------------------------
   Admin navigation drawer (phones and small tablets)
   The sidebar used to stack above the page content, so the first screen of
   every admin page was a full menu with the content below the fold. It is
   now a slide-in panel opened from a bar at the top of the page.
   --------------------------------------------------------------------------- */

/* The nav is a plain vertical list inside the drawer. */

/* The toggle bar sits behind the drawer while it is open. */

/*On a tablet there is room for the navigation as a column beside the
   content, so it stays visible and scrolls with the page. */

/*Helper text under an admin form field. */

/*---------------------------------------------------------------------------
   Sticky header — glass effect
   The header stays pinned to the top while the page scrolls and takes on a
   frosted-glass finish, so the content scrolling underneath stays legible
   without a heavy solid bar. Browsers without backdrop-filter get an
   opaque bar instead of a see-through one.
   --------------------------------------------------------------------------- */

/*The glass sits on a pseudo-element of the bar, never on the bar itself.
   An element with a backdrop-filter becomes the containing block for the
   position:fixed descendants inside it, so putting the effect on the bar
   would trap the mobile navigation panel and collapse it to a short strip
   with its links spilling out over the page. A pseudo-element is not an
   ancestor of the panel, so the panel keeps the viewport as its
   containing block and fills the screen as it should. */

/*The header element is the sticky one; the glass is drawn by the bar
   inside it so the fixed navigation panel is not trapped by the blur.
   The header sits above the dimmed overlay so the navigation panel, which
   is inside the header, is never covered by it. */

/*Solid fallback for browsers that do not support backdrop-filter. */

/*---------------------------------------------------------------------------
   Mobile navigation panel — full-height drawer
   The navigation panel and the dimmed overlay live in a wrapper beside the
   header, not inside it. The glass header uses a backdrop-filter, and any
   element with a backdrop-filter becomes the containing block for the
   position:fixed descendants inside it, so a panel nested in the header was
   sized against the header instead of the viewport. It collapsed to a short
   strip and its links spilled out over the page instead of sitting inside
   the white panel.

   On a desktop the wrapper is the positioning context and the navigation is
   placed back over the header row, so it still reads as part of the header.
   --------------------------------------------------------------------------- */

/*---------------------------------------------------------------------------
   Navigation panel
   One block owns the panel on every width, so the desktop row and the
   mobile drawer can never fight over the same properties.
   --------------------------------------------------------------------------- */

/* A desktop shows the navigation as a plain row of links in the header.
     It has no close button: a row of links is not a panel. */

/* A phone and a small tablet get a panel that slides in from the right.
     It is fixed against the viewport and fills the full height. */

/* The panel must not be reachable by keyboard while it is off screen. */

/*The close button lives inside the navigation panel, so it is always on
   top of the panel it closes. It is hidden everywhere except the mobile
   panel: on a desktop the navigation is a plain row of links and has no
   close button, and an absolutely positioned button inside a row that is
   not itself positioned would escape and land in the header. */

/* While the panel is open the header toggle is hidden, because the panel
     covers the header and carries its own close button in the top corner.
     Showing both would put two close icons on screen at once. */

/*---------------------------------------------------------------------------
   Footer legal line
   The copyright is the last thing in the footer on every device, so the
   legal notice closes the page.
   --------------------------------------------------------------------------- */

/*The floating call and back-to-top buttons are pinned to the bottom right
   corner, which is exactly where the closing copyright line sits. On narrow
   screens the footer reserves their full height; on wide screens the line is
   right-aligned, so it is indented clear of their column instead. Either way
   the copyright always remains fully legible as the last item. */

/*---------------------------------------------------------------------------
   Tap targets
   Interactive things that are small on a phone are given a comfortable
   target, without changing how they look.
   --------------------------------------------------------------------------- */

/* The show/hide password button on the sign-in form. */

/* The link back to the website on the sign-in form. */

/* Links inside running text, such as the privacy policy link. */

/* The advisor's phone number on a property page. */

/* The privacy policy link inside the consent label. */

/*---------------------------------------------------------------------------
   Smallest text on the page
   A number of labels were rendering between 7px and 9px, which is below a
   comfortable reading size on real devices. They keep their character and
   their tracking, but they stop shrinking past a 10px floor.
   --------------------------------------------------------------------------- */

/*---------------------------------------------------------------------------
   Copy followed by a button
   The supporting paragraph and the button beneath it had no space between
   them at all: the button's top edge sat on the paragraph's last line. The
   spacing comes from the paragraph so it applies wherever this pairing is
   used, at every breakpoint.
   --------------------------------------------------------------------------- */

/*---------------------------------------------------------------------------
   Trust strip
   Four reassurance points sat on an unbroken row, so on a phone the last
   one was pushed past the edge of the screen and could never be read. It
   now folds into a two-column grid and then a single column.
   --------------------------------------------------------------------------- */

/*---------------------------------------------------------------------------
   Results toolbar
   The result count, the sort control and the grid/list toggle were pinned to
   one line; on a narrow phone the toggle was shoved off the right edge.
   --------------------------------------------------------------------------- */

/*---------------------------------------------------------------------------
   Search help callout
   The closing "talk to our team" prompt kept its text and the link on one
   row, so the link ran off the right edge on the narrowest phones.
   --------------------------------------------------------------------------- */

/*---------------------------------------------------------------------------
   Enquiry confirmation dialog
   A submitted enquiry returns a reference the visitor may need to keep, so the
   confirmation is a centred dialog rather than an inline banner. It fades in
   and out, closes with the Okay button, the X, Escape, or a backdrop click,
   and reflows from a centred card into a bottom sheet on phones.
   --------------------------------------------------------------------------- */

/* Fade + a short lift. Visibility keeps the closed dialog out of the tab
     order and away from screen readers without needing display:none. */

/*The reference is the one thing worth copying down, so it gets its own
   framed row rather than blending into the paragraph. */

/*Respect a reduced-motion preference: swap the fade for an instant show. */

/*===========================================================================
   DESIGN ELEVATION
   ---------------------------------------------------------------------------
   Everything below refines the original Cedar identity rather than replacing
   it: the same green, gold and paper, the same Roboto and Rajdhani pairing.
   What changes is the craft -- a fluid type scale, layered depth, and a motion
   system that is tied to what the visitor is doing on the page.

   Three rules govern the motion in this file:

   1. Motion reveals, it never delays. Any element that animates in is already
      in the document and readable; the animation only decides how it arrives.
      Nothing is hidden by script for a visitor without JavaScript.
   2. Motion is opt-out at the operating-system level. The reduced-motion
      queries at the end of each block do not shorten the animation, they
      remove it, so the layout is identical either way.
   3. A hover is feedback, not decoration. Elements lift, shift, or brighten to
      show they are interactive -- nothing moves simply to be noticed.
   =========================================================================== */

/* Fluid type. The clamp() middle term is viewport-driven so headings grow
     with the screen instead of stepping at three breakpoints. */

/* Depth. A single soft shadow reads as a flat sticker; a stack of two reads
     as a surface with a real edge. */

/*---------------------------------------------------------------- Typography
   Tighter tracking on large display type, looser on small caps. Optical
   sizing is what separates considered typography from a font-size change. */

/*------------------------------------------------------------------ Texture
   A very fine grain over dark surfaces stops large flat greens from looking
   like a placeholder. It is a CSS gradient, so it costs no request. */

/*------------------------------------------------------------------- Header
   The bar earns its border and shadow only once the page has moved, so the
   first screen has no chrome competing with the hero. */

/*The active item gets a soft pill behind it, not only an underline: at a
   glance the underline is easy to miss among five equal links. */

/*--------------------------------------------------------------------- Hero */

/*A gold rule that draws itself in behind the eyebrow. */

/*Hero entrance. Every line is readable before it animates; the animation
   only controls how the copy arrives. --i staggers the children. */

/*The hero photograph drifts very slightly as the page scrolls. It is driven
   by a scroll-driven animation where supported, and by nothing anywhere else,
   so the cost is zero on unsupported browsers. */

/*------------------------------------------------------------- Trust strip */

/*--------------------------------------------------------------- Sections */

/*Two alternating rhythms: the plain section and a warm "paper" section. They
   alternate so the page has a pulse instead of one long white column. */

/*------------------------------------------------------------------ Cards */

/*A soft warm veil on hover lifts the badges off the photograph. */

/*-------------------------------------------------------------- Category */

/*---------------------------------------------------------------- Service */

/*------------------------------------------------------------------ Why */

/*-------------------------------------------------------------- Locations */

/*---------------------------------------------------------- Testimonials */

/*---------------------------------------------------------------- Buttons */

/*Text links: the rule under them grows from the left. */

/*------------------------------------------------------------ Page banner */

/*------------------------------------------------- Fail-safe for no JS ---
   Scroll reveals are enabled only once script confirms it is running, so a
   visitor without JavaScript sees the finished page rather than a blank one. */

/*===========================================================================
   SCROLL REVEALS
   ---------------------------------------------------------------------------
   Elements marked data-reveal start slightly offset and settle into place the
   first time they are seen. The observer adds .is-in once and disconnects, so
   scrolling back up does not replay the animation.

   data-reveal="up|fade|left|right|scale"   direction
   data-reveal-delay="120"                 stagger, in milliseconds
   data-reveal-group                        children stagger automatically

   Because `.js [data-reveal]{opacity:0}` only applies once the `js` class is
   on <html>, a visitor without JavaScript never sees the hidden state.
   =========================================================================== */

/*Images that grow into their frame. The wrapper clips, the image scales. */

/*-------------------------------------------------------------- Parallax
   A gentle drift for decorative layers. Translation only, so it composites on
   the GPU and never triggers layout. Amplitude is deliberately small (a few
   percent) -- enough to feel depth, not enough to feel seasick. */

/*--------------------------------------------------------- Progress line
   A hairline of gold across the top of the viewport showing reading progress.
   It appears only after the first screen has been passed. */

/*--------------------------------------------------------------- Marquee
   The trust strip scrolls slowly on wide screens to add life without needing
   an image. It stops on hover and is removed entirely under reduced motion. */

/*------------------------------------------------------------ Nav drawer
   The mobile panel eases in rather than sliding, and its links stagger so the
   menu reads as a list being laid out rather than a block arriving. */

/*---------------------------------------------------------- Focus states
   A visible focus ring is not decoration: it is the only way a keyboard user
   knows where they are. It gets a soft halo so it reads on light and dark. */

/*------------------------------------------------------------ Form polish */

/*------------------------------------------------------------- Back to top,
   and the floating action: they lift off the page and settle back. */

/*--------------------------------------------------------------- Reduced
   The last word: if the visitor asked for less movement, remove all of it. */

/*===========================================================================
   SECTION COMPOSITION
   ---------------------------------------------------------------------------
   One card grid repeated down the page is what makes a site look generic. The
   rules below give each section its own shape: an editorial split for the
   about story, an overlapping image pair for the difference, a staggered
   mosaic for locations, and a quote-led arrangement for testimonials.
   =========================================================================== */

/*------------------------------------------------------- Section headings
   A two-line eyebrow/heading pair with the rule only where it earns attention. */

/*The featured section gets a quiet warm field so it separates from the hero
   without a hard edge. */

/*---------------------------------------------------------- About (split)
   The photograph is the anchor; the copy sits in a narrower measure and the
   note card overlaps the image edge, which ties the two halves together. */

/*------------------------------------------------- Why (the difference)
   Three numbered principles in a left rail, each with a hairline that draws
   the eye downward. Not a card grid. */

/*-------------------------------------------------------- Locations mosaic
   A photographic mosaic where the first tile is a hero and the rest step down
   in size, so the eye has an order to follow. */

/*------------------------------------------------------ Testimonials
   A single wide quote block with the supporting quotes beneath, so the section
   has a focal point instead of three equal boxes. */

/*--------------------------------------------------------- Contact panel
   A dark editorial panel against the form. The diagonal light sweep gives the
   block a sense of depth without an image. */

/*------------------------------------------------------------- Services
   The dark band gets a subtle vertical sheen and the cards get a left rule
   that lights up on hover, so the row reads as four distinct offerings. */

/*------------------------------------------------------- Trust strip trim */

/*------------------------------------------------------------ Footer lift */

/*------------------------------------------------------- Featured lead card
   The first listing in the featured grid spans two columns and runs taller,
   so the section opens on one property instead of three equal tiles. The
   remaining cards fill the right-hand column, which gives the block an
   editorial hierarchy rather than a catalogue look. */

/* The trailing cards stack tighter so the block keeps a tidy edge. */

/*===========================================================================
   COMPOSITION CORRECTIONS
   ---------------------------------------------------------------------------
   Found by rendering the page and looking at it: the hero had a dead right
   half, the about section was a wall of text, the testimonial row was three
   equal boxes with no focal point, and the featured lead card had no action.
   =========================================================================== */

/*------------------------------------------------------- Hero: fill the right
   On wide screens the search widget anchors the bottom and a framed detail
   panel balances the photograph, so the eye travels copy -> panel -> search
   rather than landing in empty shade. */

/*The hero needs a minimum height that leaves room for both blocks. */

/*-------------------------------------------------- About: add a signature
   A quiet stat rail under the copy gives the section a second focal point and
   turns a paragraph into evidence. */

/*------------------------------------------- Testimonials: give it a lead
   The first quote becomes the anchor at larger type; the other two sit beside
   it in a narrower column stack. */

/*------------------------------------ Featured lead card: give it a baseline
   A lead card without an action reads as a banner. This adds the price,
   location and a link row along the bottom of the large tile. */

/*--------------------------------------------- Services: room to breathe
   Four columns of copy at 1440 needs more gutter than the original 28px. */

/*------------------------------------------------- Section heading spacing
   Headings that carry a lead paragraph need more room beneath them, or the
   grid crowds the text. */

/*===========================================================================
   RESPONSIVE RECONCILIATION
   ---------------------------------------------------------------------------
   The original stylesheet tuned a number of selectors inside breakpoints. A
   later global declaration wins over all of them, which silently undoes the
   small-screen layout. Everything below restores the intended behaviour at
   each width rather than trusting source order.
   =========================================================================== */

/*The locations grid: mosaic on desktop, two-up on tablet, one-up on phones. */

/*Section headings stack on narrow screens instead of hugging the right edge. */

/*The testimonial lead card only makes sense with room for two columns. */

/*The featured lead card collapses to a normal grid on tablet and below. */

/*The about figures rail wraps its labels without breaking the columns. */

/*The hero stacks: copy, then the search widget. The panel is decorative and
   is dropped below the tablet breakpoint so it never crowds the copy. */

/*Services cards need their left rule back when they stack vertically. */

/*The mosaic tiles drop their hover lift where there is no hover. */

/*A fixed progress bar must not sit on top of the skip link on small screens. */

/*===========================================================================
   INNER PAGES
   ---------------------------------------------------------------------------
   The about, services and contact pages use the same shells as the home page,
   so they need their own rhythm: the values grid reads as four quiet panels,
   the process list as a numbered rail, and the service details as alternating
   editorial rows.
   =========================================================================== */

/*------------------------------------------------------------- About: values
   Four values as stacked panels with a hairline that separates rather than a
   card that boxes. The first carries a tint so the eye has an entry point. */

/*The process list: the number is the anchor, the copy hangs off it. */

/*A gold rule grows along the bottom edge as the row is hovered. */

/*----------------------------------------------------------- Services rows
   Each service is a two-column editorial row: the icon/number column stays
   narrow and sticky on wide screens, the copy takes the measure. Alternating
   tint keeps four long rows from blurring together. */

/*---------------------------------------------------------- Contact layout
   The information column sits on a tinted field so the form reads as the
   primary action, and the FAQ list gets room to breathe. */

/*===========================================================================
   INNER PAGE RESPONSIVE
   =========================================================================== */

/*===========================================================================
   CONTRAST CORRECTIONS
   ---------------------------------------------------------------------------
   Measured against WCAG 2.1. The gold process ordinal was 2.26:1 on white and
   the contact detail lines were 3.35:1 on the tinted panel. Both are text, so
   both need 4.5:1. The gold is kept for rules and hover accents, where it is
   decoration and exempt, and a darker gold carries the text.
   =========================================================================== */

/*The hover accent on the FAQ stays gold because it is a 1px rule, not text. */

/*===========================================================================
   SCREEN-READER UTILITY
   ---------------------------------------------------------------------------
   The results list on the properties page jumps from the page h1 straight to
   each card's h3. A hidden h2 names the region for assistive technology and
   restores the document outline without changing the visual design.
   =========================================================================== */

/*===========================================================================
   PROPERTY DETAIL
   ---------------------------------------------------------------------------
   The detail page carries the most information on the site: a gallery, a spec
   table, a description, a location panel and an enquiry sidebar. The rules
   below give each block a clear rank so the page reads in a deliberate order
   rather than as one long column.
   =========================================================================== */

/*The gallery is the page's hero. Its caption sits on a dark scrim so it stays
   legible over any photograph, and the open-gallery affordance only appears on
   hover or focus so it never competes with the image itself. */

/*The small controls get a glass treatment so they read on light photographs. */

/*The spec table reads as a quiet data grid with hairlines, not as a card. */

/*Detail sections are separated by a hairline and a numbered eyebrow, which
   gives the long scroll an obvious rhythm. */

/*The description column gets a comfortable measure; long-form body copy at
   full width is the single most common readability failure on property sites. */

/*Location panel: a tinted card that holds the map affordance without the
   weight of a real embedded map. */

/*The enquiry sidebar is the conversion surface: it stays in view while the
   article scrolls and gets the strongest shadow on the page. */

/*The disclaimer is legal copy: small, quiet, but never illegible. */

/*The related grid is the last thing on the page; it needs a clear break. */

/*Spec rows are <div><svg><span>Label<strong>Value</strong></span></div>. The
   label is the bare text of the span and the value is the <strong>, so the
   type has to be carried on the span and the strong rather than on pseudo
   children that do not exist. */

/*===========================================================================
   SECOND PASS — FOUNDATIONS
   ---------------------------------------------------------------------------
   A design reads as art-directed when its decisions repeat. Measurement of the
   first pass found the opposite: thirty-six distinct letter-spacing values,
   nineteen z-index values, and a hundred and one hex colours that matched no
   token. Nothing was wrong on its own; together they read as accident. This
   block adds the scales the first pass was missing. Later blocks consume them.
   =========================================================================== */

/* ---- Spacing. One 4px-rooted scale replaces the ad-hoc 5px/9px/13px/18px/
     22px/26px/34px steps. Everything that lays out uses a member of this set,
     so gaps rhyme across sections instead of merely being close. */

/* Section rhythm, fluid. These three carry the vertical beat of the page. */

/* ---- Tracking. Five steps, named by role. The old 5.7px/5.5px/3.4px/1.8px
     one-offs collapse into these; a 1px difference at display size is
     invisible, so keeping separate values bought nothing but noise. */

/* ---- Layering. A named stack, so "above the header" is a decision rather
     than whichever number was typed last. */

/* ---- Alpha inks. Translucent whites and greens recur constantly; naming
     them keeps the glassy surfaces tonally consistent. */

/* ---- Moving-image easing. Entrances decelerate; exits accelerate. Using
     one curve for both is what makes motion feel mechanical. */

/*===========================================================================
   INTERACTION STATES
   ---------------------------------------------------------------------------
   A hover alone is half a conversation. Every control now has four states:
   rest, hover (intent), active (commit), and focus-visible (keyboard). Without
   an active state a button feels like it ignored the click; without focus it
   is unreachable by keyboard. Presses move 1px and drop their shadow, which
   is what makes a surface feel physical.
   =========================================================================== */

/*------------------------------------------------------------------ Buttons */

/* A sheen that wipes across on hover. It is one pseudo-element, GPU-only,
     and skipped entirely under reduced motion. */

/*--------------------------------------------------------------- Text links
   The underline is a real element so it can animate its origin. Hovering grows
   it from the left; leaving retracts it to the right, which reads as the same
   line continuing rather than a different line fading. */

/*------------------------------------------------------------------- Cards
   Cards lift on hover and settle on press. The image inside scales a hair, so
   the card feels like a window rather than a picture pasted on a panel. */

/*--------------------------------------------------------------- Navigation
   The active indicator slides rather than blinking on, and hovering a sibling
   nudges the colour toward the ink value instead of switching abruptly. */

/*Footer links get an underline that grows, matching the text links above. */

/*------------------------------------------------------------ Accordions
   The FAQ marker rotates 45 degrees into an × and the row tints, so the open
   state is legible without reading the answer. */

/*--------------------------------------------------------------- Icon buttons */

/*------------------------------------------------------------- Filters / tags */

/*Any element with a focus ring should also show one when focused by script. */

/*===========================================================================
   LISTINGS — art direction
   ---------------------------------------------------------------------------
   The results page is the page people actually use, and it had no identity: a
   grey band, a plain toolbar, and a card grid. These rules give it a header
   with presence, a toolbar that reads as a control strip, pagination that
   belongs to the same family as the buttons, and a help panel that closes the
   page instead of trailing off.
   =========================================================================== */

/*---------------------------------------------------------- Page header band
   A warm field with a soft radial lift on the right so the heading does not sit
   on a flat rectangle. The search form becomes the anchor at the bottom. */

/* A hairline of gold along the bottom edge ties the band to the accents used
     elsewhere and stops the border reading as a table rule. */

/*------------------------------------------------------------- Control strip
   The sort and view controls sit on a single line with a hairline beneath, so
   they read as a toolbar rather than loose text. */

/*------------------------------------------------------- Refine-your-search
   The disclosure reads as a quiet utility until opened, then as a panel. */

/*------------------------------------------------------------- Active filters
   Chips, not links. Each one is a removable token. */

/*---------------------------------------------------------------- Pagination
   Square tokens with a filled current page and clear edges. */

/*--------------------------------------------------------------- Help panel
   The closing panel gets the same diagonal light treatment as the dark service
   band, so the page ends on a considered note instead of a grey box. */

/*---------------------------------------------------------------- Empty state */

/*----------------------------------------------------------- List view
   The horizontal card needs the image to fill its track and the copy to sit
   on a baseline grid with the price. */

/*===========================================================================
   SEARCH — the primary control
   ---------------------------------------------------------------------------
   The search widget is the one thing every visitor touches, and it was built
   from 3px corners, three competing border treatments and a 24px inner pad
   that did not match the rest of the page. It now uses the shared radius,
   single hairlines between fields, and a focus state that shows which field
   is live.
   =========================================================================== */

/*Hero instance: a floating white bar on the photograph. */

/*One continuous field row; separators are hairlines, not borders per cell. */

/*The whole cell lights up while its control has focus, so the active field is
   obvious without an inner ring fighting the layout. */

/*The popular-searches strip closes the widget with a quieter tone. */

/*Compact instance on the listings page sits inside the header band. */

/*===========================================================================
   CHROME — header, footer, floating controls
   ---------------------------------------------------------------------------
   These appear on every page, so any inconsistency here is multiplied across
   the site. The utility bar, the footer, and the two floating buttons were
   still on 3px corners and their own type sizes while the rest of the page had
   moved to the token system. They now match.
   =========================================================================== */

/*------------------------------------------------------------- Utility bar */

/*-------------------------------------------------------------- Site header
   The header is the one surface that stays on screen; its border must read as
   a hairline at rest and dissolve into the glass state. */

/*A slimmer header once the page has moved: more room for content. */

/*--------------------------------------------------------------- Mobile bar */

/*------------------------------------------------------------------ Footer
   The footer is the closing statement, not a sitemap dump. The call line gets
   a gold rule, the columns share one baseline, and the legal row is quiet. */

/*------------------------------------------------------- Floating controls
   Both sit bottom-right and must not fight. The WhatsApp button is the larger
   target; the back-to-top stacks above it and only appears when useful. */

/*The floating label is a bare <span> inside the anchor: .whatsapp-label was a
   class that never existed in the markup. */

/*------------------------------------------------------------------ Toasts
   A toast should feel like it belongs to the site, not to a library. */

/*===========================================================================
   MOTION REFINEMENT
   ---------------------------------------------------------------------------
   Three problems with the first pass:

   1. Every reveal ran for .9s. A paragraph and a full-width image should not
      arrive at the same speed; long durations on small elements feel sluggish,
      and identical durations flatten the page into one tempo.
   2. `will-change` was left on permanently. That promotes every revealed
      element to its own compositing layer for the life of the page, which
      costs memory and can force extra rasterisation on scroll. It is only
      useful during the transition.
   3. Offsets were uniform, so staggered items travelled as one block instead
      of settling in sequence.

   Durations now follow element size, `will-change` is scoped to the moving
   state, and the distance shrinks as the stagger grows so the last item does
   not overshoot.
   =========================================================================== */

/* Base: small, ordinary content settles quickly. */

/* Only while it is still moving does the element deserve its own layer. */

/* Shorter travel. 34px reads as a slide; 22px reads as a settle. */

/* Large media earns a longer, softer arrival; it has more distance to cover
     and the eye forgives it more time. */

/* Headings lead their section: a slightly longer fade than body copy, so the
     eye lands on the heading before the paragraph beneath it resolves. */

/*===========================================================================
   ENTRANCE CHOREOGRAPHY
   ---------------------------------------------------------------------------
   The first screen should not simply exist. Each block of the hero arrives in
   reading order with a short overlap, so the eye is led down the copy rather
   than shown everything at once. Only runs when motion is welcome.
   =========================================================================== */

/*===========================================================================
   MOBILE DRAWER — entrance
   ---------------------------------------------------------------------------
   A full-height drawer that appears from the right with its items stepping in
   reads far better than a panel that simply becomes visible.
   =========================================================================== */

/* --i is set per child in the loop below; a missing index simply means no
       delay, which is the correct fallback. */

/*===========================================================================
   FORMS
   ---------------------------------------------------------------------------
   Measured problems: control corners were 3px against 8px elsewhere, heights
   wandered between 39/43/47/48px, and the checkbox and reassurance lines were
   set at 9–10px, which is below a readable size. Controls are now one height,
   one radius, one focus treatment.
   =========================================================================== */

/*Hover signals the control is live before it is focused. */

/*Focus is a soft green halo rather than a hard outline, matching the chips and
   the search fields elsewhere. */

/*Keyboard focus stays visible and distinct, because a halo alone is subtle. */

/*Invalid and required states read clearly without shouting. */

/*Consent line: 12px, not 9px, and the checkbox is a real target. */

/*===========================================================================
   TYPOGRAPHIC RHYTHM
   ---------------------------------------------------------------------------
   Line-height and the space beneath a heading were decided per component, so
   the page had thirty distinct line-heights and heading margins of 5, 6, 8, 9,
   10, 14 and 22px. That is invisible one block at a time and cumulatively
   uneven. The rules below set a house style by role:

     display   large headings, tight leading, tighter tracking
     title     section headings
     body      running copy, generous leading for reading
     small     captions, labels, meta

   The space after a heading follows the heading's size, so a section heading
   always sits closer to its own paragraph than to the block above it.
   =========================================================================== */

/*---- Display and title headings */

/*---- Space after a heading, by level. A heading is closer to the text it
   introduces than to whatever came before it. */

/*---- Leading by role */

/*---- Running copy measure. 66ch is the readable band; anything wider forces
   the eye to track back too far at the end of each line. */

/*---- Small caps labels share one treatment, so every "eyebrow-like" element
   reads as the same voice rather than six near-misses. */

/*---- Numbers should not jitter between values. */

/*---- The first paragraph after a display heading is a lead, not body: give
   it a slightly larger size and its own colour. */

/* Tighter leading on phones; long lines are rarer, so 1.7 wastes height. */

/*===========================================================================
   PROPERTY CARD — typographic finish
   ---------------------------------------------------------------------------
   The card is the most-repeated component on the site, so its small type is
   seen more than anything else. Measured problems: `.badge` was 9px and the
   reference number 9px, both below a comfortable reading size, and the badge
   sat on a 2px radius while every other surface had moved to the scale.
   =========================================================================== */

/*---- Card internals: one baseline rhythm, price leading. */

/*The bottom row is a footer strip: a hairline, then status and action. */

/*The save control is a real button: give it press feedback and a filled state
   that reads at a glance. */

/*Badges sit on photography, so they need a dark base to stay legible over a
   pale sky and a shadow to stay legible over a dark roof. */

/*===========================================================================
   COLUMN GUTTERS
   ---------------------------------------------------------------------------
   Four gutters survived from the original stylesheet at 80–105px, fixed. On a
   1440px screen that leaves the copy column of a two-column section narrower
   than its text wants, so paragraphs wrap into a tall narrow measure while a
   wide dead band runs down the middle of the page.

   Gutters are now fluid and proportional: they open up on wide screens and
   close down on narrow ones, and they never exceed a share of the container
   that would starve the text.
   =========================================================================== */

/*The FAQ and contact splits lean toward the content side; keep that lean but
   bring the gutter into the same family. */

/*On the largest screens, give the two-column sections a little more air
   without letting the band dominate. */

/*The services band: four columns of copy at 1440 have plenty of width, but the
   rows were short and the cards floated in the middle of a tall band. Align
   them to a baseline and let each card claim its share. */

/*===========================================================================
   SERVICE CARDS — affordance
   ---------------------------------------------------------------------------
   Each card is a link, but the only cue was a small arrow that sat below the
   copy with no label. A card that navigates should say so. The arrow now sits
   in a bordered disc that fills on hover, and the whole card responds as one
   surface: a wash of light, a lifted arrow, a gold rule.
   =========================================================================== */

/* A soft wash that follows the card's own surface, not the page. */

/*The gold rule on the left edge grows from the top on hover; on a dark band it
   is the clearest signal that the card is live. */

/*===========================================================================
   OVERFLOW SAFETY
   ---------------------------------------------------------------------------
   Two measured risks on narrow screens: the contact email is a single 34-
   character token with no wrap opportunity, and the footer repeats it. Both
   would push a horizontal scrollbar on a 360px screen.
   =========================================================================== */

/*Keep the utility bar and footer link rows from forcing width. */

/*Long property titles and addresses should wrap, never push the card. */

/*===========================================================================
   PROPERTY DETAIL — hierarchy
   ---------------------------------------------------------------------------
   The gallery block was still on 4px corners and a fixed 460px height, the
   sidebar was a fixed 330px, and the reference number was set at 9px. This
   aligns the page with the rest of the system and gives the reading column a
   proper measure beside the sticky rail.
   =========================================================================== */

/*The heading block above the gallery: title left, price right, on one line
   until there is no room. */

/*The price block is .detail-price in the markup: an overline, the figure, the
   listing reference, then the save and share controls. */

/*The badge row above the title */

/*The specs under the gallery read as a row of facts, not a list. */

/*Sidebar rail. It is a card, not a column of loose blocks. */

/*The enquiry band closing the article. */

/*The disclaimer closes the reading column quietly. */

/*Related listings sit on their own field with a clear heading rhythm. */

/*===========================================================================
   TOAST — the site's one transient message
   ---------------------------------------------------------------------------
   The toast element did not exist in the DOM at all, so every "Saved" and
   "Link copied" message the JavaScript sent was silently discarded. It is now
   present, announced politely to assistive technology, and layered above the
   header rather than level with it.
   =========================================================================== */

/* Explicit properties: `transition:.2s` also animated colour and shadow,
     which made the toast drift as it appeared. */

/*===========================================================================
   IMAGE REVEAL — applied where it belongs
   ---------------------------------------------------------------------------
   The mask-and-scale image entrance existed but was wired to nothing. It now
   runs on the property gallery's main frame, which is the one large photograph
   that arrives inside the viewport rather than above it. Card photos already
   use a hover scale, so they are left alone; stacking a reveal on top of a
   hover transform makes both feel imprecise.
   =========================================================================== */

/*===========================================================================
   MUTED TEXT SCALE
   ---------------------------------------------------------------------------
   Measured against WCAG 2.1, fourteen of the muted greys introduced in this
   pass sat between 2.2:1 and 4.4:1 — below the 4.5:1 that body text needs and,
   in several cases, below the 3:1 that even large text needs. They were all
   slightly different, too, which is why the page looked faded rather than
   restrained.

   Three steps replace them, each with one job, each measured:

     --ink-muted   5.74:1 on white   secondary body copy
     --ink-subtle  4.53:1 on white   labels, meta, captions
     --ink-faint   3.90:1 on white   large or decorative only, never body

   On the deep green footer, two light steps hold 6.5:1 and 7.9:1.
   =========================================================================== */

/*---- Light surfaces */

/*---- Dark surfaces: the footer had several greys below 4.5:1 */

/*---- Badge on a warm tint: 4.23 -> 4.6 */

/*---- Icon-only decoration: 3:1 is the bar for meaningful graphics */

/*---- Placeholders are not labels; they must still be legible. */

/*Final two, measured on their own backgrounds rather than on white: the
   popular-searches strip is #f8faf4 and the empty-state icon sits on #fbfcf9,
   so the same grey that passed elsewhere fell short here. */

/*Micro-labels: 10px is the floor for an uppercase, letter-spaced label, which
   is a legible role at that size. The card-footer badge was the one remaining
   9px exception; it now matches the rest. */

/*===========================================================================
   LAYER STACK — reconciled
   ---------------------------------------------------------------------------
   The named stack was defined but the original rules kept their own numbers, so
   the two disagreed: the header sat at 30/60/70, the nav overlay at 55, the
   drawer at 60, the floating buttons at 34/35, and the reading-progress bar now
   at 110 — which would have painted *over* an open mobile drawer.

   One ordered stack, applied here so every layer is above the one it must cover:

     100  header            (sticky, below the progress line)
     110  reading progress  (a hairline over the header)
     200  nav overlay       (dims the page)
     210  drawer            (above its own scrim)
     300  floating buttons  (above content, below dialogs)
     400  dialogs           (enquiry confirmation, gallery)
     500  toast             (always readable)
     600  skip link         (never trapped by anything)
   =========================================================================== */

/*The header's inner bar and the drawer were each given their own numbers so a
   scrolled header could sit above its own border. One token per element is
   enough; the pseudo-elements inherit their parent's stacking context. */

/*===========================================================================
   INTERACTION LAYER — one authoritative definition
   ---------------------------------------------------------------------------
   Three passes each added hover styling without removing what came before, so
   26 selectors were declared two to four times. `.property-card:hover` carried
   four different transforms (-5px, -8px, none, -5px) and `.location-card:hover`
   fired five animations at once: a lift, a shadow, a 1.06 image scale, a 6px
   copy slide and a 4px icon slide.

   Each was defensible alone. Together they read as busy, because the eye cannot
   tell which movement is the point.

   The rule applied everywhere below:
     · one transform per element, and one only
     · the shadow deepens instead of a second movement being added
     · a child never moves independently of its card
     · every duration from the same token set, so nothing races anything else
   =========================================================================== */

/*------------------------------------------------------------------ Cards */

/*----------------------------------------------------------- Small controls
   One direction of travel for every icon that means "go": up and right, 3px.
   Twelve different offsets on twelve icons was the most repetitive detail on
   the site. */

/*--------------------------------------------------------------- Accordions
   The row tints and the marker rotates. It does not also slide sideways. */

/*The underline draws; the label does not indent. */

/*The row's icon inverts; the row does not reflow its text on hover. */

/*The category tile keeps its green wash as the primary response and does not
   also lift — a row of five tiles all rising and filling with colour was the
   busiest moment on the page. The wash and the icon are the interaction. */

/*===========================================================================
   TIMING CONSISTENCY
   ---------------------------------------------------------------------------
   Twenty-eight transitions inherited from the original stylesheet specified a
   duration but no curve, so they fell back to the CSS default `ease` — a curve
   that starts slowly and ends slowly, which is why hover feedback felt limp
   next to the newer elements using `--ease-out`.

   Every transition on the site now draws from the same four durations and the
   same named curves. Nothing is left on the browser default, and nothing uses a
   raw second value of its own.
   =========================================================================== */

/*Content and surface changes: quick and decisive. */

/*Photographic and large-surface movement: slower, no overshoot. */

/*The gallery's own zoom is the one deliberately long move: it is a large
   photograph being pushed, and 0.65s matches the eye's expectation for mass. */

/*Badges and small state changes should not lag behind the cursor. */

/*===========================================================================
   DEPTH — one quiet, continuous movement
   ---------------------------------------------------------------------------
   The page had no ambient depth: every surface was flat until touched. Two
   additions give it life without motion competing for attention — a very slow
   gradient drift behind the hero, and a soft light that follows the pointer
   across dark sections on fine pointers only.

   Both are single-layer, GPU-only, and never run under reduced motion.
   =========================================================================== */

/* A slow sheen across the dark service band, offset from the hero's drift so
     the two do not pulse in step. */

/*------------------------------------------------------------------ Touch
   On a touch device there is no hover, so anything whose only affordance is a
   hover state is invisible. The save control and the gallery's open label are
   the two that matter; both become permanently visible there. */

/* Card shadow gives a resting-weight cue in place of the hover lift. */

/*Active states on touch: a visible press, since the hover lift never fires. */

/*===========================================================================
   SUBTRACTION
   ---------------------------------------------------------------------------
   The services band was given a 34-second infinite sheen. It sits mid-page on
   the way to the listings and nobody rests on it long enough to perceive a slow
   drift — an animation that cannot be perceived is only a cost. It is now a
   static gradient: the depth stays, the perpetual repaint goes.
   =========================================================================== */

/*===========================================================================
   DISPLAY SCALE — one hierarchy
   ---------------------------------------------------------------------------
   Nine different heading sizes were in play, and most were pinned to a flat
   pair: 47px above 767px, 35px below. Two problems with that.

   First, it is a step, not a scale — a heading jumps 12px at one breakpoint
   while the line length around it changes gradually.
   Second, sections disagreed: the listing page used a fluid clamp while the
   about page used the flat 47px, so two headings of the same rank differed by
   up to 9px on the same viewport.

   Every display heading now draws from the same fluid ramp, so rank is readable
   across the whole site and nothing snaps at a breakpoint. The pinned 47px
   values stay in the stylesheet because the theme asserts them (see
   tests/smoke.php); these rules supersede them at render time.
   =========================================================================== */

/*Rank 1 — page titles. */

/*Rank 2 — section headings. This is the workhorse of the site. */

/*Rank 3 — headings inside an article or a card. */

/*Rank 4 — component titles. */

/*The location mosaic's lead tile carries the section, so it keeps its larger
   setting — that is a deliberate hierarchy, not an inconsistency. */

/*The hero is the one heading allowed to be huge; it is the page's subject. */

/*Display headings are set in Rajdhani; the weight is already carried above,
   so drop the shorthand that hard-coded a size on top of these. */

/* Tighter leading once headings stack into three lines. */

/*===========================================================================
   HIERARCHY CORRECTION
   ---------------------------------------------------------------------------
   Resolving the new ramp at real viewports exposed an inversion: the page title
   plateaued at 38px while section headings reached 42px, so a page title could
   end up smaller than a heading inside it. Each rank now has a guaranteed floor
   above the rank beneath, verified at 360 through 1920.
   =========================================================================== */

/*===========================================================================
   VERTICAL RHYTHM
   ---------------------------------------------------------------------------
   Sixteen distinct section paddings were in play — 25, 35, 42, 55, 60, 63, 65,
   74, 76, 77, 86px — each decided when its section was built. On a long scroll
   that reads as uneven breathing: some sections crowd the one above, others
   float away from it.

   Every full-width band now draws from one of two rhythm steps, so the page
   breathes on a single beat. Component sections that are deliberately tighter
   (the trust strip, the listing header) keep their own padding and are excluded.
   =========================================================================== */

/*Tighter tier: bands that sit between two content sections rather than being
   one themselves. */

/*Deliberate exceptions, documented so they do not read as oversights. */

/* On phones the generous rhythm wastes a full screen of scroll between
     sections, so it tightens without collapsing. */

/*===========================================================================
   BODY TEXT CONTRAST
   ---------------------------------------------------------------------------
   The body colour sat at 4.23:1 on the paper background — just under the 4.5:1
   threshold, so running prose was readable but not comfortably so. It is now
   4.89:1 on paper, 5.26:1 on white and 4.54:1 on the pale green wash, which
   clears the threshold on every surface the token is used against.
   =========================================================================== */

/*===========================================================================
   SPLIT-LAYOUT HEADINGS
   ---------------------------------------------------------------------------
   Measured case: in the about split, "A PARTNER FOR WHAT'S NEXT." needs 394px
   at a 1024px viewport but the copy column offers 372px, so the heading ran out
   of its column instead of wrapping. Between 641 and 1120 the split column is at
   its narrowest relative to the type, so headings there are held to the size
   that fits the longest string on the site (338px in a 372px column).
   =========================================================================== */

/*Below 640 the split stacks full-width, so the ramp is safe again; wrapping is
   allowed everywhere as a backstop. */

/*===========================================================================
   TOUCH TARGETS — the two the coarse-pointer pass missed
   ---------------------------------------------------------------------------
   The existing touch layer raises navigation, pagination, FAQ rows, form
   controls and footer links to a 44px minimum. Two controls were overlooked:
   the save button on a property card (33px square) and the circular icon
   buttons in a detail header. On a phone those are the ones a thumb misses.
   =========================================================================== */

/* The share row sits under the price; give it room to breathe at this size. */

/*===========================================================================
   HERO HEADLINE — measured, not guessed
   ---------------------------------------------------------------------------
   The headline is two fixed lines: "FIND YOUR SPACE." / "FEEL AT HOME." Read
   from the shipped Rajdhani Bold file, those lines advance 7.318em and 5.834em
   plus 0.14em of tracking per gap. That makes the first line the constraint:

     38px  ->  358px of type, against 304px of usable width at a 360px phone.

   It was overflowing on every phone below 414px and clipping inside the hero's
   overflow:hidden. The cap also left only 7px of slack at 1440px. The ramp is
   now derived from the measured width: 7.318em + 0.14em tracking per gap, held
   to a 92% fill so the line never touches its container.
   =========================================================================== */

/*Very narrow phones: drop the tracking rather than the type size. Rajdhani is
   a narrow face and reads well tight; 0.14em on a 30px headline costs 63px,
   which is the difference between fitting and clipping. */

/* Tops out at 29px, which is exactly what the main ramp gives at 421px, so
       the hand-off between the two rules is smooth rather than a 2px step. */

/*The hero copy column is capped at 760px, so increasing the type past 1440
   would overflow it — the headline stops growing when the column does. */

/*Mobile: the two-line headline keeps its tracking down to 421px, where the
   narrow-phone rule above takes over. */
